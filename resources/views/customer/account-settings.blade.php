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
    @include('customer.partials.click-sound')

    @vite(['resources/css/app.css'])
    <style>
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

        /* Show / hide password: 44px eye button inside the field's right edge. */
        .pw-wrap { position: relative; }
        .pw-wrap .acct-input { padding-right: 48px; }
        .pw-eye {
            position: absolute; top: 50%; right: 0; transform: translateY(-50%);
            width: 44px; height: 44px; padding: 0; border: 0; border-radius: 0.75rem;
            display: flex; align-items: center; justify-content: center;
            background: transparent; color: var(--color-peach-deep); opacity: 0.6;
            font-size: 1.15rem; line-height: 1;
        }
        .pw-eye:hover { opacity: 1; }
        .pw-eye:focus-visible { outline: 2px solid var(--color-peach); outline-offset: -2px; opacity: 1; }

        /* Four tabs (docs/design-refs/peachy-account-settings.html): a white
           pill bar, the active tab a red gradient pill. The bar scrolls
           sideways on its own if the tabs ever outgrow it, so the page itself
           never does. Panels are hidden with the [hidden] attribute (the base
           layer above), never removed from the DOM. */
        .acct-tabs {
            position: relative; display: flex; gap: 4px; padding: 5px; overflow-x: auto;
            border-radius: 999px; background: #fff;
            box-shadow: 0 1px 2px rgb(139 26 26 / 0.06), 0 12px 32px -12px rgb(139 26 26 / 0.18);
            scrollbar-width: none; -webkit-overflow-scrolling: touch;
        }
        .acct-tabs::-webkit-scrollbar { display: none; }
        .acct-tab {
            flex: 1 0 auto; display: flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 44px; padding: 10px 14px; border: 0; border-radius: 999px;
            background: none; color: var(--color-peach-deep);
            font-size: var(--text-sm); font-weight: 500; white-space: nowrap;
            transition: background-color .2s ease, color .2s ease;
        }
        .acct-tab:hover { background: var(--color-peach-soft); }
        .acct-tab:focus-visible { outline: 3px solid var(--color-peach); outline-offset: 2px; }
        .acct-tab[aria-selected="true"] {
            background: linear-gradient(120deg, color-mix(in srgb, var(--color-peach-red) 80%, var(--color-peach)), var(--color-peach-red));
            color: #fff; box-shadow: 0 6px 14px -6px var(--color-peach-red);
        }
        .acct-tab-count {
            border-radius: 999px; padding: 1px 7px; font-size: var(--text-xs);
            background: var(--color-peach-soft); color: var(--color-peach-red);
        }
        .acct-tab[aria-selected="true"] .acct-tab-count { background: rgb(255 255 255 / 0.25); color: #fff; }
        @media (max-width: 640px) {
            .acct-tabs { border-radius: 16px; }
            .acct-tab {
                flex: 1 0 auto; min-width: 76px; flex-direction: column; gap: 3px;
                padding: 8px 6px; border-radius: 12px; font-size: var(--text-xs);
            }
            .acct-tab-count { display: none; }
        }
        .acct-panel { animation: acct-panel-in .35s ease; }
        @keyframes acct-panel-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) {
            .acct-panel { animation: none; }
            .acct-tab { transition: none; }
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
                <div class="flex min-w-0 items-center gap-2">
                    @include('customer.partials.header-back', ['href' => route('customer.more'), 'label' => 'Back', 'ariaLabel' => 'Back'])
                    <a href="{{ route('customer.menu') }}" class="flex min-w-0 items-center gap-3 no-underline">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-peach-soft text-xl sm:h-12 sm:w-12 sm:text-2xl">🍑</span>
                        <span class="min-w-0">
                            <span class="block truncate font-display text-xl font-black tracking-tight text-peach-deep sm:text-2xl">Peachy</span>
                            <span class="block truncate text-[0.8125rem] uppercase tracking-[0.18em] text-peach-red/70 sm:text-[0.875rem]">Cakes &amp; Deli Cafe</span>
                        </span>
                    </a>
                </div>

                {{-- The standard customer header actions (nav links, bell, mobile
                     cart), the same partial the More page uses. --}}
                @include('customer.partials.desktop-nav')
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
        <div class="mb-5 flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <h1 class="font-display text-[clamp(28px,4vw,40px)] font-bold leading-[1.05] text-peach-deep">Account Settings</h1>
                <p class="mt-1.5 text-sm text-peach-deep/60">Your details and rewards.</p>
            </div>
            @if($streakBranch)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-peach-soft bg-peach-soft/50 px-3 py-1 text-xs font-semibold text-peach-deep" data-testid="account-branch-chip">
                    <i class="bi bi-shop" aria-hidden="true"></i> {{ $streakBranch->name }}
                </span>
            @endif
        </div>

        {{-- Four tabs (Oct 2026), from docs/design-refs/peachy-account-settings.html:
             Overview, Rewards, Profile, Security. The profile card stays above
             the tabs and is always visible. Switching is client-side only (the
             script at the bottom); an inactive panel is hidden with the [hidden]
             attribute and stays in the DOM, and ALL FOUR panels sit inside the
             one account-update form, so Save submits every field whichever tab
             is open. The active tab lives in the URL hash (#overview, #rewards,
             #profile, #security); a validation error opens the tab that holds
             the field. Every card spans the width of the page title and the top
             nav (the header and this <main> share max-w-6xl). --}}
        @php
            // Which tab holds the field the server complained about. Account
            // Details first (it comes first in the form), then the password
            // pair. An error on a field we do not know still lands on a tab
            // with the form in it, never on Overview.
            $startTab = 'overview';
            if ($errors->hasAny(['password', 'current_password'])) {
                $startTab = 'security';
            }
            if ($errors->hasAny(['name', 'address', 'contact_number', 'email'])) {
                $startTab = 'profile';
            }
            if ($startTab === 'overview' && $errors->any()) {
                $startTab = 'profile';
            }
            $startForced = $startTab !== 'overview';
            $tabPoints = (int) ($loyalty['bonus_points'] ?? 0);
        @endphp
        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-3">
                {{-- Profile card with the Points / Streak / Stamps chips --}}
                @include('customer.partials.account-header')

                <nav id="acct-tabs" class="acct-tabs" role="tablist" aria-label="Account sections"
                    data-testid="account-tabs"
                    data-start-tab="{{ $startTab }}" data-start-forced="{{ $startForced ? '1' : '0' }}"
                    data-saved="{{ session('success') ? '1' : '0' }}">
                    <button type="button" class="acct-tab" role="tab" id="t-overview" data-tab="overview" aria-controls="p-overview"
                        aria-selected="{{ $startTab === 'overview' ? 'true' : 'false' }}" tabindex="{{ $startTab === 'overview' ? '0' : '-1' }}">
                        <i class="bi bi-house-door" aria-hidden="true"></i>Overview
                    </button>
                    <button type="button" class="acct-tab" role="tab" id="t-rewards" data-tab="rewards" aria-controls="p-rewards"
                        aria-selected="{{ $startTab === 'rewards' ? 'true' : 'false' }}" tabindex="{{ $startTab === 'rewards' ? '0' : '-1' }}">
                        <i class="bi bi-gift" aria-hidden="true"></i>Rewards <span class="acct-tab-count" data-testid="tab-rewards-count">{{ number_format($tabPoints) }} pts</span>
                    </button>
                    <button type="button" class="acct-tab" role="tab" id="t-profile" data-tab="profile" aria-controls="p-profile"
                        aria-selected="{{ $startTab === 'profile' ? 'true' : 'false' }}" tabindex="{{ $startTab === 'profile' ? '0' : '-1' }}">
                        <i class="bi bi-person" aria-hidden="true"></i>Profile
                    </button>
                    <button type="button" class="acct-tab" role="tab" id="t-security" data-tab="security" aria-controls="p-security"
                        aria-selected="{{ $startTab === 'security' ? 'true' : 'false' }}" tabindex="{{ $startTab === 'security' ? '0' : '-1' }}">
                        <i class="bi bi-lock" aria-hidden="true"></i>Security
                    </button>
                </nav>
            </div>

            <form id="account-form" action="{{ route('customer.account.update') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-5">
                @csrf
                @method('PUT')

                {{-- ================= OVERVIEW ================= --}}
                {{-- Bonus banner, then the Streak Calendar (7 of 12) beside the
                     Reward Challenge and Reward Stamp Card stacked (5 of 12);
                     stacked on one column under 1100px. Read-only figures and
                     links only, no fields. All of it is PER BRANCH — the branch
                     the customer is ordering from ($streakBranch). $streak is
                     null and the two stamp partials render nothing while the
                     owner's master switch or that branch's own switch is off;
                     with no branch picked yet, a short card asks them to
                     choose one. --}}
                <section class="acct-panel flex flex-col gap-5" role="tabpanel" id="p-overview" data-tab="overview" aria-labelledby="t-overview" @if($startTab !== 'overview') hidden @endif>
                    @include('customer.partials.account-bonus-banner')

                    @php $stampsOn = $freeDessert['enabled'] ?? false; @endphp
                    @if($streak || $stampsOn)
                        <div class="grid grid-cols-12 gap-5">
                            @if($streak)
                                @include('customer.partials.streak-calendar')
                            @endif
                            @if($stampsOn)
                                <div class="col-span-12 flex min-w-0 flex-col gap-5 {{ $streak ? 'min-[1100px]:col-span-5' : '' }}">
                                    @include('customer.partials.free-dessert-progress')
                                    @include('customer.partials.free-dessert-stamp-card')
                                </div>
                            @endif
                        </div>
                    @endif
                    @if(! $streak && ! $streakBranch && $streakOn)
                        <section class="acct-card p-4 sm:p-[22px]" data-testid="streak-choose-branch">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="acct-icon text-lg" aria-hidden="true">
                                    <i class="bi bi-shop"></i>
                                </span>
                                <p class="min-w-0 flex-1 basis-40 text-sm text-peach-deep/70">
                                    Choose a branch to see your streak and stamps.
                                </p>
                                <a href="{{ route('customer.menu') }}"
                                    class="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-peach-red px-4 py-2 text-sm font-bold text-white no-underline transition hover:bg-peach-deep max-sm:w-full max-sm:justify-center">
                                    Choose a branch <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        </section>
                    @endif
                </section>

                {{-- ================= REWARDS ================= --}}
                {{-- Account Benefits (7 of 12) beside Recent Bonuses (5 of 12) --}}
                <section class="acct-panel" role="tabpanel" id="p-rewards" data-tab="rewards" aria-labelledby="t-rewards" @if($startTab !== 'rewards') hidden @endif>
                    <div class="grid grid-cols-12 gap-5">
                        @include('customer.partials.account-benefits')
                    </div>
                </section>

                {{-- ================= PROFILE ================= --}}
                <section class="acct-panel flex flex-col gap-5" role="tabpanel" id="p-profile" data-tab="profile" aria-labelledby="t-profile" @if($startTab !== 'profile') hidden @endif>
                    {{-- ---------- ACCOUNT DETAILS ---------- --}}
                    <section class="acct-card p-4 sm:p-[22px]">
                        <div class="mb-4 flex items-start gap-3">
                            <span class="acct-icon text-lg">
                                <i class="bi bi-person"></i>
                            </span>
                            <div class="min-w-0">
                                <h2 class="font-display text-lg font-bold leading-snug text-peach-deep">Account Details</h2>
                                <p class="text-sm text-peach-deep/60">Your details.</p>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="acc-name" class="mb-1.5 block text-sm font-semibold text-peach-deep">Name</label>
                                <div class="relative">
                                    <i class="bi bi-person pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-peach-rose" aria-hidden="true"></i>
                                    <input id="acc-name" class="acct-input" type="text" name="name" value="{{ $customer?->name ?? '' }}" autocomplete="name">
                                </div>
                                @error('name')
                                    <p class="mt-1 text-sm text-[#b42318]" data-testid="name-error">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="acc-address" class="mb-1.5 block text-sm font-semibold text-peach-deep">Address</label>
                                <div class="relative">
                                    <i class="bi bi-geo-alt pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-peach-rose" aria-hidden="true"></i>
                                    <input id="acc-address" class="acct-input" type="text" name="address" value="{{ $customer?->address ?? '' }}" autocomplete="street-address">
                                </div>
                                @error('address')
                                    <p class="mt-1 text-sm text-[#b42318]" data-testid="address-error">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="acc-contact" class="mb-1.5 block text-sm font-semibold text-peach-deep">Contact</label>
                                <div class="relative">
                                    <i class="bi bi-telephone pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-peach-rose" aria-hidden="true"></i>
                                    <input id="acc-contact" class="acct-input" type="text" name="contact_number" value="{{ $customer?->contact_number ?? '' }}" inputmode="numeric" autocomplete="tel">
                                </div>
                                @error('contact_number')
                                    <p class="mt-1 text-sm text-[#b42318]" data-testid="contact-number-error">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="acc-email" class="mb-1.5 block text-sm font-semibold text-peach-deep">Email Address</label>
                                <div class="relative">
                                    <i class="bi bi-envelope pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-peach-rose" aria-hidden="true"></i>
                                    <input id="acc-email" class="acct-input" type="email" name="email" value="{{ $customer?->email ?? '' }}" autocomplete="email">
                                </div>
                                @error('email')
                                    <p class="mt-1 text-sm text-[#b42318]" data-testid="email-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>
                </section>

                {{-- ================= SECURITY ================= --}}
                <section class="acct-panel flex flex-col gap-5" role="tabpanel" id="p-security" data-tab="security" aria-labelledby="t-security" @if($startTab !== 'security') hidden @endif>
                    {{-- ---------- EMAIL VERIFICATION ---------- --}}
                    @php $emailVerified = (bool) $customer?->hasVerifiedEmail(); @endphp
                    <section class="acct-card p-4 sm:p-[22px]" data-testid="email-verification">
                        <div class="mb-4 flex items-start gap-3">
                            <span class="acct-icon text-lg">
                                <i class="bi bi-envelope-check"></i>
                            </span>
                            <div class="min-w-0">
                                <h2 class="font-display text-lg font-bold leading-snug text-peach-deep">Email Verification</h2>
                                @if($emailVerified)
                                    <p class="text-sm text-peach-deep/60">Your email is confirmed.</p>
                                @else
                                    <p class="text-sm text-peach-deep/60" data-testid="verify-to-earn">Verify your email to start earning bonus points.</p>
                                @endif
                            </div>
                        </div>

                        @if($emailVerified)
                            <div class="flex items-center gap-3 rounded-2xl border border-[#cfe9da] bg-[#eef8f2] p-4 text-sm font-semibold text-[#2f8a5b]">
                                <i class="bi bi-patch-check-fill text-lg" aria-hidden="true"></i> Your email is verified.
                            </div>
                        @else
                            {{-- A real <form> here would nest inside the page's
                                 account-update form (invalid HTML), so this posts
                                 via fetch instead, same pattern as confirmDelete()
                                 below. --}}
                            <div class="flex flex-wrap items-center justify-between gap-3.5 rounded-2xl border border-peach-soft bg-peach-soft/50 p-4">
                                <p id="verify-email-status" class="min-w-0 text-sm font-semibold text-peach-deep">
                                    <i class="bi bi-exclamation-triangle"></i> Your email isn't verified yet.
                                </p>
                                <button type="button" onclick="resendVerification()" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-2.5 text-sm font-semibold text-white shadow-[0_10px_20px_-10px_var(--color-peach-red)] transition hover:bg-peach-deep">
                                    <i class="bi bi-send"></i> Resend email
                                </button>
                            </div>
                        @endif
                    </section>

                    {{-- ---------- PASSWORD MANAGEMENT ---------- --}}
                    <section class="acct-card p-4 sm:p-[22px]">
                        <div class="mb-4 flex items-start gap-3">
                            <span class="acct-icon text-lg">
                                <i class="bi bi-lock"></i>
                            </span>
                            <div class="min-w-0">
                                <h2 class="font-display text-lg font-bold leading-snug text-peach-deep">Password Management</h2>
                                <p class="text-sm text-peach-deep/60">Leave blank to keep your current password.</p>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="acc-password" class="mb-1.5 block text-sm font-semibold text-peach-deep">
                                    New Password
                                    <span class="ml-1 font-normal text-peach-deep/50">(optional)</span>
                                </label>
                                <div class="pw-wrap relative">
                                    <i class="bi bi-lock pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-peach-rose" aria-hidden="true"></i>
                                    <input id="acc-password" class="acct-input" type="password" name="password" maxlength="20" autocomplete="new-password">
                                    <button type="button" class="pw-eye" data-password-toggle="acc-password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                                </div>
                                @error('password')
                                    <p class="mt-1 text-sm text-[#b42318]" data-testid="password-error">{{ $message }}</p>
                                @enderror
                            </div>
                            {{-- Checked by updateAccount() only when the email or password is changing. --}}
                            <div>
                                <label for="acc-current-password" class="mb-1.5 block text-sm font-semibold text-peach-deep">
                                    Current Password
                                    <span class="ml-1 font-normal text-peach-deep/50">(to change email or password)</span>
                                </label>
                                <div class="pw-wrap relative">
                                    <i class="bi bi-key pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-peach-rose" aria-hidden="true"></i>
                                    <input id="acc-current-password" class="acct-input" type="password" name="current_password" autocomplete="current-password">
                                    <button type="button" class="pw-eye" data-password-toggle="acc-current-password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                                </div>
                                @error('current_password')
                                    <p class="mt-1 text-sm text-[#b42318]" data-testid="current-password-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>
                </section>

                {{-- Discount IDs are intentionally not stored in the customer account. --}}

                {{-- ---------- ACTIONS ---------- --}}
                {{-- A normal block at the end of the form, shown on every tab
                     (it sits outside the tab panels, inside the same form). --}}
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-[18px] border border-peach-soft bg-white p-3 shadow-sm" data-testid="account-action-bar">
                    <p class="hidden text-sm text-peach-deep/60 sm:block">Deleting your account is permanent.</p>
                    <div class="flex w-full gap-2 sm:ml-auto sm:w-auto">
                        <button type="button" onclick="confirmDelete()"
                            class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-full border-[1.5px] border-[#f3b6aa] bg-white px-3 py-2.5 sm:px-5 text-sm font-semibold text-[#b42318] transition hover:bg-[#fdecea] sm:flex-none">
                            <i class="bi bi-trash"></i> Delete Account
                        </button>
                        <button type="submit"
                            class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-full bg-peach-red px-3 py-2.5 text-sm font-semibold text-white shadow-[0_10px_20px_-10px_var(--color-peach-red)] transition hover:bg-peach-deep sm:flex-none sm:px-5">
                            <i class="bi bi-check2"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    @include('customer.partials.navbar')

    <script>
        // Show / hide password: flips the input type only; the value is never read.
        document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
            var target = document.getElementById(btn.getAttribute('data-password-toggle'));
            var icon = btn.querySelector('i');
            if (!target) return;
            btn.addEventListener('click', function () {
                var show = target.type === 'password';
                target.type = show ? 'text' : 'password';
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                if (icon) {
                    icon.classList.toggle('bi-eye', !show);
                    icon.classList.toggle('bi-eye-slash', show);
                }
                target.focus();
            });
        });

        // Tabs: client-side only. Panels are hidden with [hidden], never removed,
        // so every field still submits in the one form whichever tab is open.
        // Start tab, in order: the tab holding a field the server rejected
        // (data-start-forced), then the URL hash (#overview / #rewards /
        // #profile / #security, or any id inside a panel, e.g. the benefits
        // card's #free-dessert link), then the tab the customer saved from,
        // then Overview.
        (function () {
            var tablist = document.getElementById('acct-tabs');
            var form = document.getElementById('account-form');
            if (!tablist || !form) return;
            var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
            var names = tabs.map(function (t) { return t.getAttribute('data-tab'); });
            var current = tablist.getAttribute('data-start-tab') || 'overview';

            function select(name, opts) {
                opts = opts || {};
                if (names.indexOf(name) === -1) return;
                current = name;
                tabs.forEach(function (t) {
                    var on = t.getAttribute('data-tab') === name;
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    t.tabIndex = on ? 0 : -1;
                    var panel = document.getElementById(t.getAttribute('aria-controls'));
                    if (panel) panel.hidden = !on;
                    if (on) {
                        if (opts.focus) t.focus();
                        // Keep the active tab in view inside the scrolling bar
                        // (never the page): set the bar's own scroll position.
                        var left = t.offsetLeft - (tablist.clientWidth - t.offsetWidth) / 2;
                        tablist.scrollLeft = Math.max(0, left);
                    }
                });
                if (!opts.keepHash) {
                    try { history.replaceState(null, '', '#' + name); } catch (e) {}
                }
            }

            function tabOf(el) {
                var panel = el && el.closest ? el.closest('[role="tabpanel"]') : null;
                return panel ? panel.getAttribute('data-tab') : null;
            }

            function fromHash(scroll) {
                var id = '';
                try { id = decodeURIComponent(location.hash.slice(1)); } catch (e) {}
                if (!id) return false;
                if (names.indexOf(id) !== -1) { select(id, { keepHash: true }); return true; }
                var el = document.getElementById(id);
                var name = tabOf(el);
                if (!name) return false;
                select(name, { keepHash: true });
                if (scroll) el.scrollIntoView();
                return true;
            }

            tabs.forEach(function (tab, i) {
                tab.addEventListener('click', function () { select(tab.getAttribute('data-tab')); });
                tab.addEventListener('keydown', function (e) {
                    var to = null;
                    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') to = (i + 1) % tabs.length;
                    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') to = (i - 1 + tabs.length) % tabs.length;
                    else if (e.key === 'Home') to = 0;
                    else if (e.key === 'End') to = tabs.length - 1;
                    if (to === null) return;
                    e.preventDefault();
                    select(tabs[to].getAttribute('data-tab'), { focus: true });
                });
            });

            window.addEventListener('hashchange', function () { fromHash(true); });

            // A browser refuses to submit while a field it cannot show is
            // invalid, and says nothing. Open that field's tab so it can.
            form.addEventListener('invalid', function (e) {
                var name = tabOf(e.target);
                if (name && name !== current) select(name);
            }, true);

            // Remember where the customer saved from; the redirect back drops the hash.
            form.addEventListener('submit', function () {
                try { sessionStorage.setItem('acct-tab-on-save', current); } catch (e) {}
            });

            if (tablist.getAttribute('data-start-forced') === '1') {
                select(tablist.getAttribute('data-start-tab'), { keepHash: true });
            } else if (!fromHash(true) && tablist.getAttribute('data-saved') === '1') {
                var saved = null;
                try { saved = sessionStorage.getItem('acct-tab-on-save'); sessionStorage.removeItem('acct-tab-on-save'); } catch (e) {}
                select(names.indexOf(saved) !== -1 ? saved : 'profile', { keepHash: true });
            }
        })();

        function resendVerification() {
            const status = document.getElementById('verify-email-status');
            fetch('{{ route("customer.email-verification.resend") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            }).then(function() {
                if (status) {
                    status.innerHTML = '<i class="bi bi-check-circle"></i> Verification email sent! Please check your inbox.';
                }
            });
        }

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

    @include('customer.partials.idle-timeout')
</body>

</html>

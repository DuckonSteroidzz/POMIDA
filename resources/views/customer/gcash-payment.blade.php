<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GCash Payment - Peachy</title>

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
            --color-peach-blush: #EF8585;
            --color-peach-rose: #F6B49B;
            --color-peach-sand: #F8D7B0;
            --color-peach-soft: #FDE8DE;
            --color-peach-cream: #FFFDF9;

            --font-display: "Fraunces", ui-serif, Georgia, serif;
            --font-body: "Karla", ui-sans-serif, system-ui, sans-serif;
        }

        @layer base {
            html {
                -webkit-text-size-adjust: 100%;
            }

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

            h1, h2, h3, .font-display {
                font-family: var(--font-display);
            }

            button, a {
                font-family: inherit;
            }

            button:not(:disabled), [onclick] { cursor: pointer; }
        }

        @utility card-surface {
            background-color: #fff;
            border: 1px solid var(--color-peach-soft);
            border-radius: 1.25rem;
            box-shadow:
                0 1px 2px rgb(139 26 26 / 0.04),
                0 8px 24px -18px rgb(139 26 26 / 0.35);
        }
    </style>

    <style>
        .paper-bg {
            background-image:
                radial-gradient(
                    circle at 12% 8%,
                    rgba(248, 215, 176, 0.55),
                    transparent 42%
                ),
                radial-gradient(
                    circle at 88% 0%,
                    rgba(246, 180, 155, 0.4),
                    transparent 38%
                );
        }

        .qr-shadow {
            box-shadow:
                0 10px 30px rgba(139, 26, 26, 0.10);
        }

        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .rise {
            animation: riseIn 0.45s cubic-bezier(0.22, 0.9, 0.3, 1) both;
        }

        .rise-1 {
            animation-delay: 0.05s;
        }

        .rise-2 {
            animation-delay: 0.12s;
        }

        .rise-3 {
            animation-delay: 0.18s;
        }

        @media (prefers-reduced-motion: reduce) {
            .rise {
                animation: none;
            }
        }
    </style>
    @include('partials.session-guard')

    @include('customer.partials.readable-text')

</head>

<body class="min-h-screen bg-peach-cream">

    {{-- ================= HEADER ================= --}}
    <header class="sticky top-0 z-40 border-b border-peach-soft bg-peach-cream/95 backdrop-blur">
        <div class="mx-auto w-full max-w-5xl px-4 sm:px-6">
            <div class="flex items-center justify-between gap-3 py-3 sm:py-4">

                <a
                    href="{{ route('customer.orders') }}"
                    class="flex min-w-0 items-center gap-3 no-underline"
                >
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-peach-soft text-xl sm:h-12 sm:w-12 sm:text-2xl">
                        🍑
                    </span>

                    <span class="min-w-0">
                        <span class="block truncate font-display text-xl font-black tracking-tight text-peach-deep sm:text-2xl">
                            Peachy
                        </span>

                        <span class="block truncate text-[0.68rem] uppercase tracking-[0.18em] text-peach-red/70 sm:text-[0.72rem]">
                            Cakes &amp; Deli Cafe
                        </span>
                    </span>
                </a>

                {{-- Notifications. The customer sits on this page waiting for
                     staff to verify a GCash payment, which is exactly when an
                     order status change matters most. --}}
                @include('customer.partials.notification-bell')

                <a
                    href="{{ route('customer.orders') }}"
                    class="inline-flex shrink-0 items-center gap-2 rounded-full border border-peach-soft bg-white px-3 py-2 text-sm font-bold text-peach-deep no-underline transition hover:border-peach hover:text-peach-red sm:px-4"
                >
                    <i class="bi bi-arrow-left"></i>
                    <span class="hidden sm:inline">Orders</span>
                </a>

            </div>
        </div>
    </header>


    {{-- ================= MAIN ================= --}}
    <main class="paper-bg mx-auto w-full max-w-5xl px-4 pb-28 pt-6 sm:px-6 sm:pt-8">

        {{-- Page heading --}}
        <div class="rise rise-1 mx-auto mb-6 max-w-2xl text-center">

            <p class="text-[0.68rem] font-bold uppercase tracking-[0.22em] text-peach-red/70">
                GCash Payment
            </p>

            <h1 class="mt-1 font-display text-3xl font-black leading-tight tracking-tight text-peach-deep sm:text-4xl">
                Complete Your Payment
            </h1>

            <p class="mx-auto mt-2 max-w-lg text-sm leading-relaxed text-peach-deep/55">
                Scan the store's GCash QR code and pay the exact amount shown below.
            </p>

        </div>


        {{-- Success message --}}
        @if(session('success'))

            <div class="rise rise-2 mx-auto mb-5 max-w-2xl rounded-2xl border border-green-200 bg-green-50 p-4 text-green-700">

                <div class="flex items-start gap-3">

                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-green-100">
                        <i class="bi bi-check-circle-fill"></i>
                    </span>

                    <div>
                        <p class="font-bold">
                            Payment Submitted
                        </p>

                        <p class="mt-0.5 text-sm leading-relaxed">
                            {{ session('success') }}
                        </p>
                    </div>

                </div>

            </div>

        @endif


        {{-- Error messages --}}
        @if($errors->any())

            <div class="rise rise-2 mx-auto mb-5 max-w-2xl rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700">

                @foreach($errors->all() as $error)

                    <div class="flex items-start gap-2 text-sm">
                        <i class="bi bi-exclamation-circle-fill mt-0.5"></i>
                        <span>{{ $error }}</span>
                    </div>

                @endforeach

            </div>

        @endif


        <div class="mx-auto grid max-w-4xl gap-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(300px,0.85fr)]">

            {{-- ================= QR SECTION ================= --}}
            <section class="rise rise-2 card-surface overflow-hidden">

                <div class="bg-gradient-to-br from-peach-sand/60 via-peach-soft to-white px-5 py-5 text-center sm:px-7">

                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-white text-xl shadow-sm">
                        <i class="bi bi-qr-code text-peach-red"></i>
                    </span>

                    <h2 class="mt-3 font-display text-xl font-black tracking-tight text-peach-deep sm:text-2xl">
                        Scan to Pay
                    </h2>

                    <p class="mt-1 text-xs font-medium text-peach-deep/55">
                        Open your GCash app and scan this QR code.
                    </p>

                </div>


                <div class="px-5 py-6 sm:px-8 sm:py-8">

                    @if($gcashQrSetting && $gcashQrSetting->value)

                        <div class="mx-auto w-full max-w-sm">

                            <div class="qr-shadow overflow-hidden rounded-2xl border border-peach-soft bg-white p-3 sm:p-4">

                            <img
                                src="{{ asset('storage/' . ltrim($gcashQrSetting->value, '/')) }}"
                                alt="Store GCash QR Code"
                                class="mx-auto block aspect-square w-full object-contain"
                            >

                            </div>

@if($gcashPhoneSetting && $gcashPhoneSetting->value)
    <div class="mt-4 text-center">
        <p class="text-xs font-semibold text-peach-deep/50">
            GCash Number
        </p>

        <p class="mt-1 font-display text-lg font-black tracking-wide text-peach-deep">
            {{ $gcashPhoneSetting->value }}
        </p>
    </div>
@endif

<p class="mt-3 text-center text-[0.7rem] font-medium leading-relaxed text-peach-deep/45">
    Scan the QR code above using the GCash app.
</p>

                        </div>

                    @else

                        <div class="mx-auto max-w-sm rounded-2xl border-2 border-dashed border-peach-soft bg-peach-cream p-8 text-center">

                            <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-peach-soft text-3xl text-peach-red/50">
                                <i class="bi bi-qr-code"></i>
                            </span>

                            <h3 class="mt-4 font-display text-lg font-black text-peach-deep">
                                GCash QR Unavailable
                            </h3>

                            <p class="mt-2 text-sm leading-relaxed text-peach-deep/55">
                                The store has not uploaded a GCash QR code yet.
                                Please choose another payment method or contact staff.
                            </p>

                        </div>

                    @endif

                </div>

            </section>


            {{-- ================= PAYMENT DETAILS ================= --}}
            <aside class="rise rise-3 space-y-5">

                {{-- Amount --}}
                <section class="card-surface overflow-hidden">

                    <div class="border-b border-peach-soft bg-peach-cream px-5 py-4 sm:px-6">

                        <p class="text-[0.68rem] font-bold uppercase tracking-[0.18em] text-peach-red/70">
                            Amount to Pay
                        </p>

                        <div class="mt-1 flex items-end justify-between gap-3">

                            <span class="font-display text-3xl font-black leading-none tracking-tight text-peach-deep sm:text-4xl">
                                ₱{{ number_format($order->total, 2) }}
                            </span>

                            <span class="rounded-full bg-peach-soft px-2.5 py-1 text-[0.68rem] font-black text-peach-red">
                                GCash
                            </span>

                        </div>

                    </div>


                    <div class="px-5 py-5 sm:px-6">

                        <div class="space-y-3">

                            <div class="flex items-center justify-between gap-3">

                                <span class="text-xs font-semibold text-peach-deep/50">
                                    Order Number
                                </span>

                                <span class="text-sm font-bold text-peach-deep">
                                    {{ $order->order_number }}
                                </span>

                            </div>

                            @if($order->discount_amount > 0)

                                <div class="flex items-center justify-between gap-3">

                                    <span class="text-xs font-semibold text-green-600">
                                        Discount Applied
                                    </span>

                                    <span class="text-sm font-bold text-green-600">
                                        -₱{{ number_format($order->discount_amount, 2) }}
                                    </span>

                                </div>

                            @endif

                            <div class="border-t border-peach-soft pt-3">

                                <p class="text-xs leading-relaxed text-peach-deep/50">
                                    Please pay exactly
                                    <strong class="text-peach-deep">
                                        ₱{{ number_format($order->total, 2) }}
                                    </strong>
                                    through GCash.
                                </p>

                            </div>

                        </div>

                    </div>

                </section>


                {{-- Instructions --}}
                <section class="card-surface p-5 sm:p-6">

                    <h2 class="font-display text-lg font-black tracking-tight text-peach-deep">
                        How to Pay
                    </h2>

                    <ol class="mt-4 space-y-3">

                        <li class="flex items-start gap-3">

                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-peach-soft text-xs font-black text-peach-red">
                                1
                            </span>

                            <p class="pt-1 text-sm leading-relaxed text-peach-deep/65">
                                Open your <strong class="text-peach-deep">GCash</strong> app.
                            </p>

                        </li>

                        <li class="flex items-start gap-3">

                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-peach-soft text-xs font-black text-peach-red">
                                2
                            </span>

                            <p class="pt-1 text-sm leading-relaxed text-peach-deep/65">
                                Scan the store's QR code.
                            </p>

                        </li>

                        <li class="flex items-start gap-3">

                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-peach-soft text-xs font-black text-peach-red">
                                3
                            </span>

                            <p class="pt-1 text-sm leading-relaxed text-peach-deep/65">
                                Pay exactly
                                <strong class="text-peach-deep">
                                    ₱{{ number_format($order->total, 2) }}
                                </strong>.
                            </p>

                        </li>

                        <li class="flex items-start gap-3">

                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-peach-soft text-xs font-black text-peach-red">
                                4
                            </span>

                            <p class="pt-1 text-sm leading-relaxed text-peach-deep/65">
                                After paying, click <strong class="text-peach-deep">I Have Paid</strong>.
                            </p>

                        </li>

                    </ol>

                </section>


                {{-- Payment Status / Action --}}
                <section class="card-surface p-5 sm:p-6">

                    <div
                        id="gcashPaymentResult"
                        style="display:none;"
                        class="mb-5"
                    >
                    </div>

                    @if($order->payment_status === 'awaiting_verification')

                        <div id="gcashWaitingSection" class="text-center">

                            <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-amber-50 text-2xl text-amber-600">
                                <i class="bi bi-hourglass-split"></i>
                            </span>

                            <h2 class="mt-4 font-display text-xl font-black tracking-tight text-peach-deep">
                                Waiting for Verification
                            </h2>

                            <p class="mt-2 text-sm leading-relaxed text-peach-deep/55">
                                Your GCash payment has been submitted.
                                Our staff will verify the payment before continuing your order.
                            </p>

                            <div class="mt-4 rounded-xl bg-peach-cream px-4 py-3 text-xs font-semibold text-peach-deep/60">
                                <i class="bi bi-info-circle"></i>
                                Please wait while our staff checks your payment.
                            </div>

                        </div>

                    @elseif($order->payment_status === 'pending')

                        @if($gcashQrSetting && $gcashQrSetting->value)

                            <div class="text-center">

                                <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-green-50 text-2xl text-green-600">
                                    <i class="bi bi-wallet2"></i>
                                </span>

                                <h2 class="mt-4 font-display text-xl font-black tracking-tight text-peach-deep">
                                    Finished Paying?
                                </h2>

                                <p class="mt-2 text-sm leading-relaxed text-peach-deep/55">
                                    Only click this button after you have completed the GCash payment.
                                </p>

                                <form
                                    action="{{ route('customer.gcash-payment.paid', $order->id) }}"
                                    method="POST"
                                    class="mt-5"
                                >
                                    @csrf

                                    <button
                                        type="submit"
                                        class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-peach-red px-5 py-3.5 text-sm font-bold text-white transition hover:bg-peach-deep active:scale-[0.99]"
                                    >
                                        <i class="bi bi-check-circle"></i>
                                        I Have Paid
                                    </button>

                                </form>

                                <p class="mt-3 text-[0.68rem] leading-relaxed text-peach-deep/40">
                                    Your payment will be reviewed by our staff.
                                </p>

                            </div>

                        @else

                            <div class="text-center">

                                <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-red-50 text-2xl text-red-500">
                                    <i class="bi bi-exclamation-circle"></i>
                                </span>

                                <h2 class="mt-4 font-display text-xl font-black tracking-tight text-peach-deep">
                                    Payment Unavailable
                                </h2>

                                <p class="mt-2 text-sm leading-relaxed text-peach-deep/55">
                                    GCash payment is currently unavailable because the store QR code has not been configured.
                                </p>

                            </div>

                        @endif

                    @else

                        <div class="text-center">

                            <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-peach-soft text-2xl text-peach-red">
                                <i class="bi bi-info-circle"></i>
                            </span>

                            <h2 class="mt-4 font-display text-xl font-black tracking-tight text-peach-deep">
                                Payment Status
                            </h2>

                            <p class="mt-2 text-sm leading-relaxed text-peach-deep/55">
                                Your current payment status is
                                <strong>
                                    {{ ucfirst(str_replace('_', ' ', $order->payment_status)) }}
                                </strong>.
                            </p>

                        </div>

                    @endif

                </section>

            </aside>

        </div>

    </main>


    {{-- ================= MOBILE NAV ================= --}}
    @includeIf('customer.partials.bottom-nav')


    <script>
        document.addEventListener('DOMContentLoaded', function () {

            const statusUrl = @json(route('customer.gcash-payment.status', $order->id));
            const ordersUrl = @json(route('customer.orders'));

            let checking = true;
            let statusInterval = null;

            async function checkPaymentStatus() {

                if (!checking) {
                    return;
                }

                try {
                    const response = await fetch(statusUrl, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        cache: 'no-store'
                    });

                    if (!response.ok) {
                        return;
                    }

                    const data = await response.json();

                    if (!data.success) {
                        return;
                    }

                    if (data.payment_status === 'paid') {

                        checking = false;

                        if (statusInterval) {
                            clearInterval(statusInterval);
                        }

                        showPaymentResult(
                            'approved',
                            'Payment Approved',
                            'Your GCash payment has been verified by our staff.'
                        );

                        startReturnCountdown();

                    } else if (data.payment_status === 'rejected') {

                        checking = false;

                        if (statusInterval) {
                            clearInterval(statusInterval);
                        }

                        showPaymentResult(
                            'rejected',
                            'Payment Rejected',
                            'Your GCash payment could not be verified. Please contact our staff.'
                        );

                        startReturnCountdown();
                    }

                } catch (error) {
                    console.error('GCash payment status check failed:', error);
                }
            }

            function showPaymentResult(type, title, message) {

                const box = document.getElementById('gcashPaymentResult');
                const waitingSection = document.getElementById('gcashWaitingSection');

                if (!box) {
                    return;
                }

                if (waitingSection) {
                    waitingSection.style.display = 'none';
                }

                const isApproved = type === 'approved';

                box.style.display = 'block';

                box.innerHTML = `
                    <div style="
                        padding:1.1rem 1.25rem;
                        border-radius:1rem;
                        background:${isApproved ? '#EAF8EF' : '#FDECEC'};
                        border:1px solid ${isApproved ? '#B7E4C7' : '#F3B5B5'};
                        color:${isApproved ? '#28734A' : '#A83232'};
                        text-align:center;
                    ">
                        <div style="
                            font-size:1.5rem;
                            margin-bottom:0.35rem;
                        ">
                            <i class="bi ${isApproved
                                ? 'bi-check-circle-fill'
                                : 'bi-x-circle-fill'}"></i>
                        </div>

                        <div style="
                            font-family:Georgia,serif;
                            font-size:1.15rem;
                            font-weight:800;
                            margin-bottom:0.25rem;
                        ">
                            ${title}
                        </div>

                        <div style="
                            font-size:0.78rem;
                            opacity:0.8;
                            line-height:1.5;
                        ">
                            ${message}
                        </div>

                        <div
                            id="gcashCountdown"
                            style="
                                margin-top:0.7rem;
                                font-size:0.72rem;
                                font-weight:700;
                            "
                        >
                            Returning to your orders in 5 seconds...
                        </div>
                    </div>
                `;
            }

            function startReturnCountdown() {

                let seconds = 5;
                const countdown = document.getElementById('gcashCountdown');

                const timer = setInterval(function () {

                    seconds--;

                    if (countdown) {
                        countdown.textContent =
                            `Returning to your orders in ${seconds} second${seconds === 1 ? '' : 's'}...`;
                    }

                    if (seconds <= 0) {
                        clearInterval(timer);
                        window.location.href = ordersUrl;
                    }

                }, 1000);
            }

            // Only poll while the order is awaiting verification.
            @if($order->payment_status === 'awaiting_verification')
                checkPaymentStatus();

                statusInterval = setInterval(function () {
                    if (!checking) {
                        clearInterval(statusInterval);
                        return;
                    }

                    checkPaymentStatus();
                }, 3000);
            @endif

        });
    </script>

</body>

</html>

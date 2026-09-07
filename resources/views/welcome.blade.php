<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Peachy Cakes & Deli Cafe — Dine In or Pick Up</title>

    <meta name="description"
        content="Peachy Cakes and Deli Cafe serves peach layer cakes, fresh deli plates and slow-poured coffee daily.">


    @include('partials.session-guard')

    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

    @include('partials.icon-stability')
    <link href="/vendor/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --deep-red: #8B1A1A;
            --terracotta: #C0392B;
            --peach: #F4845F;
            --peach-soft: #E88A6E;
            --light-peach: #FDE8DE;
            --cream: #FFFDF9;
            --text: #5A2920;
            --muted: #8A6A61;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
        }

        body {
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--text);

            background:
                linear-gradient(
                    135deg,
                    #F8D7B0 0%,
                    #F6B49B 50%,
                    #EF8585 100%
                );

            min-height: 100vh;
        }

        h1,
        h2,
        h3 {
            font-family: "Fraunces", Georgia, serif;
        }

        a {
            text-decoration: none;
        }

        /* =========================
           PAGE
        ========================= */

        .landing-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;

            padding: clamp(1rem, 4vw, 2.5rem);
            position: relative;
            overflow: hidden;
        }

        /* =========================
           BACKGROUND
        ========================= */

        .background-image {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;

            object-fit: cover;

            z-index: 0;
        }

        .background-overlay {
            position: absolute;
            inset: 0;

            background:
                linear-gradient(
                    135deg,
                    rgba(139, 26, 26, 0.68),
                    rgba(192, 57, 43, 0.42),
                    rgba(244, 132, 95, 0.48)
                );

            z-index: 1;
        }

        /* Decorative soft glow */

        .background-glow {
            position: absolute;

            width: min(70vw, 650px);
            height: min(70vw, 650px);

            right: -15%;
            bottom: -25%;

            border-radius: 50%;

            background: rgba(255, 253, 249, 0.12);

            filter: blur(2px);

            z-index: 2;
        }

        /* =========================
           MAIN CARD
        ========================= */

        .landing-card {
            position: relative;
            z-index: 3;

            width: 100%;
            max-width: 460px;

            background: rgba(255, 253, 249, 0.96);

            border: 1px solid rgba(244, 132, 95, 0.4);

            border-radius: clamp(1.5rem, 4vw, 2rem);

            padding: clamp(2rem, 6vw, 3.25rem);

            text-align: center;

            box-shadow:
                0 30px 70px -30px rgba(90, 41, 32, 0.55);

            backdrop-filter: blur(10px);
        }

        /* =========================
           BRAND
        ========================= */

        .eyebrow {
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.65rem;
            font-weight: 700;

            letter-spacing: 0.3em;
            text-transform: uppercase;

            color: var(--peach-soft);
        }

        .brand-title {
            margin-top: 1rem;

            font-family: "Fraunces", Georgia, serif;

            font-size: clamp(2.2rem, 8vw, 3.3rem);

            font-weight: 600;

            line-height: 1.05;

            letter-spacing: -0.025em;

            color: var(--deep-red);
        }

        .brand-title span {
            display: block;

            margin-top: 0.35rem;

            font-size: clamp(1.45rem, 5vw, 2rem);

            color: var(--peach-soft);
        }

        /* =========================
           DESCRIPTION
        ========================= */

        .description {
            width: 100%;
            max-width: 350px;

            margin: 1.25rem auto 0;

            font-size: 0.95rem;

            line-height: 1.7;

            color: var(--muted);
        }

        /* =========================
           BUTTONS
        ========================= */

        .actions {
            display: flex;

            flex-direction: column;

            gap: 0.75rem;

            margin-top: 2rem;
        }

        .action-button {
            display: inline-flex;

            align-items: center;
            justify-content: center;

            gap: 0.5rem;

            width: 100%;

            min-height: 52px;

            padding: 0.8rem 1.5rem;

            border-radius: 999px;

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.95rem;
            font-weight: 700;

            letter-spacing: 0.04em;

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease,
                background 0.2s ease;
        }

        .action-button:hover {
            transform: translateY(-2px);
        }

        .action-button:active {
            transform: translateY(0);
        }

        .btn-dine {
            color: #fff;

            background: var(--deep-red);

            box-shadow:
                0 8px 20px rgba(139, 26, 26, 0.25);
        }

        .btn-dine:hover {
            background: var(--terracotta);

            box-shadow:
                0 12px 24px rgba(139, 26, 26, 0.3);
        }

        .btn-pickup {
            color: var(--deep-red);

            background: var(--peach);

            border: 1px solid rgba(139, 26, 26, 0.15);

            box-shadow:
                0 8px 20px rgba(244, 132, 95, 0.2);
        }

        .btn-pickup:hover {
            background: var(--peach-soft);
        }

        /* =========================
           QUICK TABLE CODE ENTRY
           A customer who already knows their table's code (it is printed
           right under the QR on the card) can type it here without first
           clicking through to the Dine In page. Deliberately understated —
           this is a shortcut for the returning/confident customer, not the
           primary call to action, which stays the two buttons above.
        ========================= */

        .code-toggle {
            display: inline-block;
            margin-top: 1.1rem;
            background: none;
            border: 0;
            padding: 0;
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--muted);
            text-decoration: underline;
            cursor: pointer;
        }

        .code-toggle:hover {
            color: var(--deep-red);
        }

        .code-panel {
            margin-top: 1rem;
            text-align: left;
        }

        .code-panel[hidden] {
            display: none;
        }

        label[for="welcomeTableCodeInput"] {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
            display: block;
            margin-bottom: 0.3rem;
        }

        #welcomeTableCodeInput {
            width: 100%;
            min-height: 48px;
            padding: 0.65rem 0.85rem;
            border: 1px solid rgba(139, 26, 26, 0.25);
            border-radius: 0.75rem;
            background: #fff;
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--text);
            text-align: center;
        }

        #welcomeTableCodeInput:focus {
            outline: 2px solid var(--peach);
            outline-offset: 1px;
        }

        #welcomeCodeSubmit {
            width: 100%;
            min-height: 46px;
            margin-top: 0.6rem;
            border: 0;
            border-radius: 999px;
            background: var(--deep-red);
            color: #fff;
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
        }

        #welcomeCodeSubmit:hover {
            background: var(--terracotta);
        }

        .welcome-code-error {
            margin-top: 0.55rem;
            padding: 0.55rem 0.7rem;
            border-radius: 0.6rem;
            background: #FDECEA;
            border: 1px solid rgba(192, 57, 43, 0.35);
            color: #8B1A1A;
            font-size: 0.76rem;
            line-height: 1.4;
            text-align: left;
        }

        .welcome-code-error[hidden] {
            display: none;
        }

        /* =========================
           OPEN DAILY
        ========================= */

        .hours {
            margin-top: 2rem;

            padding-top: 1.25rem;

            border-top: 1px solid rgba(139, 26, 26, 0.12);
        }

        .hours-label {
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.65rem;

            font-weight: 700;

            letter-spacing: 0.25em;

            text-transform: uppercase;

            color: var(--muted);
        }

        .hours-value {
            margin-top: 0.3rem;

            font-family: "Fraunces", Georgia, serif;

            font-size: 1.2rem;

            color: var(--deep-red);
        }

        /* =========================
           MOBILE
        ========================= */

        @media (max-width: 480px) {

            .landing-page {
                padding: 1rem;
            }

            .landing-card {
                padding: 2rem 1.35rem;
                border-radius: 1.5rem;
            }

            .brand-title {
                font-size: 2.25rem;
            }

            .brand-title span {
                font-size: 1.45rem;
            }

            .description {
                font-size: 0.9rem;
            }

            .action-button {
                min-height: 50px;
            }
        }

        /* =========================
           DESKTOP
        ========================= */

        @media (min-width: 640px) {

            .actions {
                flex-direction: row;
            }

            .action-button {
                flex: 1;
            }
        }

        /* =========================
           REDUCED MOTION
        ========================= */

        @media (prefers-reduced-motion: reduce) {

            .action-button {
                transition: none;
            }

            .action-button:hover {
                transform: none;
            }
        }
    </style>
</head>

<body>

    <main class="landing-page">

        {{-- Background --}}
        {{--
            If you have a cafe background image, place it at:
            public/images/hero-cafe.png
        --}}
        <img
            src="{{ asset('images/hero-cafe.png') }}"
            alt=""
            class="background-image"
            onerror="this.style.display='none';"
        >

        <div class="background-overlay"></div>

        <div class="background-glow"></div>

        {{-- Main Peachy Card --}}
        <section class="landing-card">

            <p class="eyebrow">
                Bakery · Deli · Cafe
            </p>

            <h1 class="brand-title">
                Peachy Cakes

                <span>
                    and Deli Cafe
                </span>
            </h1>

            <p class="description">
                Peach layer cakes, honest deli plates and slow-poured coffee —
                baked and carved fresh every morning.
            </p>

            {{-- Actions --}}
            <div class="actions">

                <a
                    href="{{ route('customer.dineinqr') }}"
                    class="action-button btn-dine"
                >
                    <i class="bi bi-shop"></i>
                    Dine In
                </a>

                <a
                    href="{{ route('customer.login', ['order_type' => 'pick_up']) }}"
                    class="action-button btn-pickup"
                >
                    <i class="bi bi-bag"></i>
                    Pick Up
                </a>

            </div>

            {{-- =========================
                 QUICK TABLE CODE ENTRY
                 For the customer who already has their table's code — printed
                 under the QR on the card — and would rather type it here than
                 click through to the Dine In page. Validates through the exact
                 same door as everywhere else: App\Services\TableEntry via
                 AuthController::processQr().
            ========================== --}}

            <button
                id="welcomeCodeToggle"
                type="button"
                class="code-toggle"
                aria-expanded="{{ session('error') ? 'true' : 'false' }}"
                aria-controls="welcomeCodePanel"
            >
                Already have your table's code?
            </button>

            <div
                id="welcomeCodePanel"
                class="code-panel"
                @if(!session('error')) hidden @endif
            >
                <label for="welcomeTableCodeInput">Table code</label>

                <input
                    id="welcomeTableCodeInput"
                    type="text"
                    inputmode="text"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="10"
                    placeholder="e.g. 7K4M9QXP"
                    value="{{ old('table_code') }}"
                >

                <button id="welcomeCodeSubmit" type="button">
                    Start Dine-In Order
                </button>

                @if(session('error'))
                    <p class="welcome-code-error" role="alert">
                        {{ session('error') }}
                    </p>
                @else
                    <p id="welcomeCodeError" class="welcome-code-error" role="alert" hidden></p>
                @endif
            </div>

            {{-- Opening Hours --}}
            <div class="hours">

                <p class="hours-label">
                    Open daily
                </p>

                <p class="hours-value">
                    7am – 9pm
                </p>

            </div>

        </section>

    </main>

    @include('customer.partials.dine-in-account-prompt')

    <script>
        const welcomeCodeToggle = document.getElementById('welcomeCodeToggle');
        const welcomeCodePanel = document.getElementById('welcomeCodePanel');
        const welcomeTableCodeInput = document.getElementById('welcomeTableCodeInput');
        const welcomeCodeSubmit = document.getElementById('welcomeCodeSubmit');
        const welcomeCodeError = document.getElementById('welcomeCodeError');

        welcomeCodeToggle.addEventListener('click', () => {
            const isHidden = welcomeCodePanel.hidden;

            welcomeCodePanel.hidden = !isHidden;
            welcomeCodeToggle.setAttribute('aria-expanded', String(isHidden));

            if (isHidden) {
                welcomeTableCodeInput.focus();
            }
        });

        function submitWelcomeCode() {
            const value = welcomeTableCodeInput.value.trim();

            // Only an obvious empty submission is caught here. Everything else
            // — format, whether it exists, whether the branch is open — is
            // decided server-side by App\Services\TableEntry.
            if (value === '') {
                if (welcomeCodeError) {
                    welcomeCodeError.textContent = 'Please enter the code printed on your table.';
                    welcomeCodeError.hidden = false;
                }
                welcomeTableCodeInput.focus();
                return;
            }

            if (welcomeCodeError) {
                welcomeCodeError.hidden = true;
            }

            window.dineInPrompt.open(value);
        }

        welcomeCodeSubmit.addEventListener('click', submitWelcomeCode);

        welcomeTableCodeInput.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitWelcomeCode();
            }
        });
    </script>

</body>

</html>
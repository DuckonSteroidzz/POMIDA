<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Peachy Cakes & Deli Cafe — Dine In</title>

    <meta name="description"
        content="Scan the QR code on your table, or type its code, to start your order at Peachy Cakes & Deli Cafe.">

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
            /* Matches --text-secondary in customer/partials/readable-text.blade.php
               (8.56:1 on cream). The old #8A6A61 sat at 4.79:1, which is thin
               for the 0.8rem status line. */
            --muted: #6B4038;
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
            min-height: 100vh;
            background:
                linear-gradient(
                    135deg,
                    #F8D7B0 0%,
                    #F6B49B 50%,
                    #EF8585 100%
                );
            overflow-x: hidden;
        }

        h1,
        h2,
        h3 {
            font-family: "Fraunces", Georgia, serif;
        }

        /* =========================
           EXACT WELCOME BACKGROUND
        ========================= */

        .background-image {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 0;
        }

        .background-overlay {
            position: fixed;
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

        .background-glow {
            position: fixed;
            width: min(70vw, 650px);
            height: min(70vw, 650px);
            right: -15%;
            bottom: -25%;
            border-radius: 50%;
            background: rgba(255, 253, 249, 0.12);
            filter: blur(2px);
            z-index: 2;
            pointer-events: none;
        }

        /* =========================
           PAGE
           Single centered card — there is no camera panel to sit beside any
           more, so this now matches welcome.blade.php's layout exactly rather
           than the old two-column grid built around a scanner.
        ========================= */

        .dine-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(1rem, 4vw, 2.5rem);
            position: relative;
            z-index: 3;
        }

        .dine-card {
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
            font-size: clamp(2.2rem, 7vw, 3.2rem);
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

        .description {
            width: 100%;
            max-width: 350px;
            margin: 1.25rem auto 0;
            font-size: 0.95rem;
            line-height: 1.7;
            color: var(--muted);
        }

        /* =========================
           TABLE CODE ENTRY
           This is now the ONE way in from this page: scan the printed QR with
           the phone's own camera app (which lands straight on the menu, no
           screen of ours in between — see AuthController::showMenu()), or type
           the code printed under it here.
        ========================= */

        .code-panel {
            width: 100%;
            max-width: 330px;
            margin: 2rem auto 0;
            text-align: left;
        }

        .code-panel h2 {
            font-size: 1.1rem;
            color: var(--deep-red);
            margin-bottom: 0.4rem;
            text-align: center;
        }

        .code-help {
            font-size: 0.82rem;
            line-height: 1.55;
            color: var(--muted);
            text-align: center;
            margin-bottom: 1rem;
        }

        label[for="tableCodeInput"] {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
            display: block;
            margin-bottom: 0.35rem;
        }

        #tableCodeInput {
            width: 100%;
            min-height: 52px;
            padding: 0.7rem 0.9rem;
            border: 1px solid rgba(139, 26, 26, 0.25);
            border-radius: 0.75rem;
            background: #fff;
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--text);
            text-align: center;
        }

        #tableCodeInput:focus {
            outline: 2px solid var(--peach);
            outline-offset: 1px;
        }

        #manualSubmit {
            width: 100%;
            min-height: 52px;
            margin-top: 0.85rem;
            border: 0;
            border-radius: 999px;
            background: var(--deep-red);
            color: #fff;
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 8px 20px rgba(139, 26, 26, 0.25);
        }

        #manualSubmit:hover {
            transform: translateY(-2px);
            background: var(--terracotta);
        }

        .manual-error {
            margin-top: 0.7rem;
            padding: 0.6rem 0.75rem;
            border-radius: 0.6rem;
            background: #FDECEA;
            border: 1px solid rgba(192, 57, 43, 0.35);
            color: #8B1A1A;
            font-size: 0.78rem;
            line-height: 1.45;
            text-align: left;
        }

        .manual-error[hidden] {
            display: none;
        }

        .links {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 1.5rem;
            font-size: 0.85rem;
        }

        .links a {
            color: var(--muted);
            font-weight: 600;
            text-decoration: none;
        }

        .links a:hover {
            color: var(--deep-red);
        }

        /* =========================
           MOBILE
        ========================= */

        @media (max-width: 480px) {
            .dine-page {
                padding: 1rem;
            }

            .dine-card {
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
        }

        @media (prefers-reduced-motion: reduce) {
            #manualSubmit {
                transition: none;
            }

            #manualSubmit:hover {
                transform: none;
            }
        }
    </style>
</head>

<body>

    {{-- =========================
         SAME BACKGROUND AS WELCOME
    ========================== --}}

    <img
        src="{{ asset('images/hero-cafe.png') }}"
        alt=""
        class="background-image"
        onerror="this.style.display='none';"
    >

    <div class="background-overlay"></div>

    <div class="background-glow"></div>


    <main class="dine-page">

        <section class="dine-card">

            <p class="eyebrow">
                Bakery · Deli · Cafe
            </p>

            <h1 class="brand-title">
                Peachy Cakes
                <span>and Deli Cafe</span>
            </h1>

            <p class="description">
                Scan the QR code on your table with your phone's camera to jump
                straight to the menu, or type the table's code below.
            </p>

            {{-- =========================
                 TABLE CODE ENTRY
                 The only entry point on this page. Scanning the printed QR is
                 handled entirely by the customer's own camera app opening the
                 QR's URL — see AuthController::showMenu() — so no camera
                 screen of ours sits between a scan and the menu.
            ========================== --}}

            <div class="code-panel">
                <h2>Enter your table code</h2>

                <p class="code-help">
                    It's printed on the card on your table, under the QR code.
                    Ask a staff member if you can't find it.
                </p>

                <label for="tableCodeInput">Table code</label>

                <input
                    id="tableCodeInput"
                    type="text"
                    inputmode="text"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="10"
                    placeholder="e.g. 7K4M9QXP"
                    value="{{ old('table_code') }}"
                >

                <button
                    id="manualSubmit"
                    type="button"
                >
                    Start Dine-In Order
                </button>

                @if(session('error'))
                    <p class="manual-error" role="alert">
                        {{ session('error') }}
                    </p>
                @else
                    <p id="manualError" class="manual-error" role="alert" hidden></p>
                @endif
            </div>

            <div class="links">
                <a href="{{ route('home') }}">
                    ← Back to start
                </a>
            </div>

        </section>

    </main>


    @include('customer.partials.dine-in-account-prompt')

    <script>
        const tableCodeInput = document.getElementById('tableCodeInput');
        const manualSubmit = document.getElementById('manualSubmit');
        const manualError = document.getElementById('manualError');

        function submitTableCode() {
            const value = tableCodeInput.value.trim();

            // Only an obvious empty submission is caught here. Everything else
            // — format, whether it exists, whether the branch is open — is
            // decided server-side by App\Services\TableEntry.
            if (value === '') {
                if (manualError) {
                    manualError.textContent = 'Please enter the code printed on your table.';
                    manualError.hidden = false;
                }
                tableCodeInput.focus();
                return;
            }

            if (manualError) {
                manualError.hidden = true;
            }

            window.dineInPrompt.open(value);
        }

        manualSubmit.addEventListener('click', submitTableCode);

        tableCodeInput.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitTableCode();
            }
        });

        /*
            Keep the code-entry form's CSRF token alive.

            The hidden #qrForm (in partials/dine-in-account-prompt) posts with a
            native form submit, so the session-guard fetch wrapper never gets to
            swap a fresh token onto it. Left open past SESSION_LIFETIME the form
            would post a dead token and the customer would hit the branded 419
            page — the one dead end this flow must not have. Every other refusal
            here (ERR_QR_STALE, ERR_SESSION_IDLE) redirects back to this same
            form with an inline message instead.

            Same fetch-and-swap shape session-guard already uses for the
            X-CSRF-TOKEN header; here it also rewrites the hidden _token field
            the form actually submits. A poll well inside the session lifetime
            also keeps the session itself from ageing out while the tab is open.
        */
        (function () {
            var REFRESH_MS = 15 * 60 * 1000; // SESSION_LIFETIME is 480 min.

            function refreshCsrfToken() {
                fetch('{{ route('customer.session-token') }}', {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data || !data.token) { return; }

                        var meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) { meta.setAttribute('content', data.token); }

                        var field = document.querySelector('#qrForm input[name="_token"]');
                        if (field) { field.value = data.token; }
                    })
                    .catch(function () { /* transient/offline — the next tick retries */ });
            }

            setInterval(refreshCsrfToken, REFRESH_MS);
        })();
    </script>

</body>

</html>

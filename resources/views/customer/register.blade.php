<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Account | Peachy Cakes & Deli Cafe</title>


    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

    {{-- Bootstrap Icons — used for the real-time field-validation checkmark
         (same vendored stylesheet as customer/login.blade.php). --}}
    @include('partials.icon-stability')
    <link href="/vendor/bootstrap-icons.css"
        rel="stylesheet">

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

        h1, h2, h3 {
            font-family: "Fraunces", Georgia, serif;
        }

        a {
            text-decoration: none;
        }

        /* =====================================================
           SAME WELCOME / LOGIN BACKGROUND
        ===================================================== */

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

        /* =====================================================
           PAGE
        ===================================================== */

        .register-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(1rem, 4vw, 2.5rem);
            position: relative;
            z-index: 3;
        }

        /* =====================================================
           REGISTER CARD
        ===================================================== */

        .register-card {
            width: 100%;
            max-width: 920px;

            display: grid;
            grid-template-columns: minmax(260px, 0.75fr) minmax(0, 1.25fr);

            overflow: hidden;

            background: rgba(255, 253, 249, 0.98);

            border-radius: clamp(1.5rem, 4vw, 2rem);

            box-shadow:
                0 30px 70px -30px rgba(90, 41, 32, 0.6);

            border: 1px solid rgba(244, 132, 95, 0.35);
        }

        /* =====================================================
           BRAND PANEL
        ===================================================== */

        .brand-panel {
            padding: clamp(2rem, 5vw, 3.25rem);

            background:
                linear-gradient(
                    150deg,
                    var(--deep-red) 0%,
                    var(--terracotta) 55%,
                    var(--peach-soft) 100%
                );

            color: #fff;

            display: flex;
            flex-direction: column;
            justify-content: space-between;

            gap: 2rem;
        }

        .eyebrow {
            margin: 0;

            font-size: 0.65rem;
            font-weight: 700;

            letter-spacing: 0.3em;
            text-transform: uppercase;

            color: var(--light-peach);
        }

        .brand-title {
            margin: 0.8rem 0 0;

            font-family: "Fraunces", Georgia, serif;

            font-weight: 600;

            font-size: clamp(2rem, 4vw, 3rem);

            line-height: 1.05;

            letter-spacing: -0.025em;
        }

        .brand-title span {
            display: block;

            margin-top: 0.35rem;

            color: #FFD5C5;

            font-size: 0.68em;
        }

        .brand-description {
            margin-top: 1.25rem;

            color: rgba(255, 255, 255, 0.86);

            font-size: 0.92rem;

            line-height: 1.65;
        }

        .brand-list {
            list-style: none;

            display: grid;

            gap: 0.8rem;
        }

        .brand-list li {
            position: relative;

            padding-left: 1.5rem;

            color: rgba(255, 255, 255, 0.9);

            font-size: 0.85rem;

            line-height: 1.45;
        }

        .brand-list li::before {
            content: "✓";

            position: absolute;
            left: 0;

            color: #FFD5C5;

            font-weight: 700;
        }

        /* =====================================================
           FORM PANEL
        ===================================================== */

        .form-panel {
            padding: clamp(2rem, 5vw, 3.25rem);

            background: var(--cream);
        }

        .form-panel h2 {
            color: var(--deep-red);

            font-size: clamp(1.8rem, 4vw, 2.35rem);

            line-height: 1.1;
        }

        .subtitle {
            margin-top: 0.55rem;
            margin-bottom: 1.5rem;

            color: var(--muted);

            font-size: 0.9rem;

            line-height: 1.55;
        }

        /* =====================================================
           ALERT
        ===================================================== */

        .alert {
            margin-bottom: 1.25rem;

            padding: 0.8rem 1rem;

            border-radius: 0.85rem;

            color: var(--deep-red);

            background: rgba(192, 57, 43, 0.08);

            border: 1px solid rgba(192, 57, 43, 0.2);

            font-size: 0.82rem;

            line-height: 1.5;
        }

        .alert ul {
            padding-left: 1.2rem;
        }

        /* =====================================================
           FIELDS
        ===================================================== */

        .field-grid {
            display: grid;

            grid-template-columns: repeat(2, minmax(0, 1fr));

            gap: 1rem;
        }

        .field {
            display: block;
            min-width: 0;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field-label {
            display: block;

            margin-bottom: 0.4rem;

            font-size: 0.78rem;

            font-weight: 700;

            color: var(--text);
        }

        .field input {
            width: 100%;

            min-height: 46px;

            padding: 0.7rem 0.85rem;

            border:
                1px solid
                rgba(138, 106, 97, 0.25);

            border-radius: 0.75rem;

            background: #fff;

            color: var(--text);

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.9rem;

            outline: none;

            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        .field input::placeholder {
            color: #B39A93;
        }

        .field input:focus {
            border-color: var(--peach);

            box-shadow:
                0 0 0 3px
                rgba(244, 132, 95, 0.15);
        }

        .field input.is-invalid {
            border-color: var(--terracotta);
        }

        /* Companion to .is-invalid: a field that has passed the same check the
           server enforces on submit. Green tone (#155724 / #2E7D5B) is the one
           already used for success messaging elsewhere in the app — no new
           colour, and the tick is the shared bi-check-lg icon. */
        .field input.is-valid {
            border-color: #2E7D5B;
        }

        .field-valid-icon {
            display: none;
            margin-left: 0.4rem;
            color: #155724;
            font-size: 0.78rem;
            vertical-align: -0.05em;
        }

        .field.is-valid .field-valid-icon {
            display: inline-block;
        }

        .error-text {
            display: block;

            margin-top: 0.3rem;

            color: var(--terracotta);

            font-size: 0.75rem;

            line-height: 1.35;
        }

        .hint {
            display: block;

            margin-top: 0.3rem;

            color: var(--muted);

            font-size: 0.72rem;
        }

        /* =====================================================
           TERMS
        ===================================================== */

        .tos {
            margin-top: 1.15rem;
            font-size: 0.78rem;
            text-align: center;
        }

        .tos a,
        .terms a,
        .signin a {
            color: var(--deep-red);

            font-weight: 700;

            text-decoration: underline;

            text-underline-offset: 3px;
        }

        .terms {
            display: flex;

            align-items: flex-start;

            gap: 0.55rem;

            margin-top: 0.65rem;

            color: var(--muted);

            font-size: 0.76rem;

            line-height: 1.5;

            cursor: pointer;
        }

        .terms input {
            flex: 0 0 auto;

            width: 16px;
            height: 16px;

            margin-top: 2px;

            accent-color: var(--deep-red);

            cursor: pointer;
        }

        /* =====================================================
           SUBMIT
        ===================================================== */

        .btn-submit {
            width: 100%;

            min-height: 50px;

            margin-top: 1.25rem;

            border: none;

            border-radius: 999px;

            background: var(--deep-red);

            color: #fff;

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.92rem;

            font-weight: 700;

            letter-spacing: 0.04em;

            cursor: pointer;

            box-shadow:
                0 8px 20px
                rgba(139, 26, 26, 0.25);

            transition:
                transform 0.2s ease,
                background 0.2s ease,
                box-shadow 0.2s ease;
        }

        .btn-submit:hover {
            background: var(--terracotta);

            transform: translateY(-2px);

            box-shadow:
                0 12px 24px
                rgba(139, 26, 26, 0.3);
        }

        /* =====================================================
           SIGN IN
        ===================================================== */

        .signin {
            margin-top: 1.25rem;

            padding-top: 1rem;

            border-top:
                1px solid
                rgba(139, 26, 26, 0.12);

            text-align: center;

            color: var(--muted);

            font-size: 0.82rem;
        }

        /* =====================================================
           TERMS MODAL
        ===================================================== */

        .modal {
            position: fixed;
            inset: 0;

            display: none;

            align-items: center;
            justify-content: center;

            padding: 1rem;

            background: rgba(59, 35, 32, 0.6);

            z-index: 100;
        }

        .modal:target {
            display: flex;
        }

        .modal-card {
            width: 100%;
            max-width: 620px;

            max-height: 85vh;

            overflow: hidden;

            background: var(--cream);

            border-radius: 1.25rem;

            box-shadow:
                0 30px 70px
                rgba(59, 35, 32, 0.3);
        }

        .modal-head {
            padding: 1.5rem 1.5rem 1rem;

            border-bottom:
                1px solid
                rgba(139, 26, 26, 0.1);
        }

        .modal-head .eyebrow {
            color: var(--peach-soft);
        }

        .modal-head h3 {
            margin-top: 0.35rem;

            color: var(--deep-red);

            font-size: 1.5rem;
        }

        .modal-body {
            padding: 1.25rem 1.5rem;

            max-height: 55vh;

            overflow-y: auto;

            color: var(--muted);

            font-size: 0.82rem;

            line-height: 1.65;
        }

        .modal-body h4 {
            margin: 1rem 0 0.35rem;

            color: var(--deep-red);

            font-family: "Fraunces", Georgia, serif;

            font-size: 1rem;
        }

        .modal-body h4:first-child {
            margin-top: 0;
        }

        .modal-body ul {
            padding-left: 1.2rem;
        }

        .modal-foot {
            display: flex;

            justify-content: flex-end;

            gap: 0.65rem;

            padding: 1rem 1.5rem;

            border-top:
                1px solid
                rgba(139, 26, 26, 0.1);
        }

        .btn-ghost,
        .btn-accept {
            padding: 0.7rem 1.1rem;

            border-radius: 999px;

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.8rem;

            font-weight: 700;

            cursor: pointer;
        }

        .btn-ghost {
            background: #fff;

            color: var(--muted);

            border:
                1px solid
                var(--light-peach);
        }

        .btn-accept {
            border: none;

            background: var(--deep-red);

            color: #fff;
        }

        .btn-accept:hover {
            background: var(--terracotta);
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 800px) {

            .register-card {
                grid-template-columns: 1fr;

                max-width: 560px;
            }

            .brand-panel {
                padding: 2rem;

                gap: 1.25rem;
            }

            .brand-list {
                display: none;
            }

            .brand-description {
                margin-bottom: 0;
            }

            .form-panel {
                padding: 2rem;
            }
        }

        @media (max-width: 520px) {

            .register-page {
                padding: 1rem;
            }

            .register-card {
                border-radius: 1.35rem;
            }

            .brand-panel,
            .form-panel {
                padding: 1.5rem;
            }

            .field-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .modal-foot {
                flex-direction: column;
            }

            .btn-ghost,
            .btn-accept {
                width: 100%;

                text-align: center;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .btn-submit {
                transition: none;
            }

            .btn-submit:hover {
                transform: none;
            }
        }
    </style>
</head>

<body>

    {{-- SAME BACKGROUND USED BY THE LOVABLE WELCOME / CUSTOMER LOGIN --}}

    <img
        src="{{ asset('images/hero-cafe.png') }}"
        alt=""
        class="background-image"
        onerror="this.style.display='none';"
    >

    <div class="background-overlay"></div>

    <div class="background-glow"></div>


    <main class="register-page">

        <section class="register-card">

            {{-- =================================================
                 BRANDING
            ================================================== --}}

            <aside class="brand-panel">

                <div>

                    <p class="eyebrow">
                        Customer account
                    </p>

                    <h1 class="brand-title">
                        Peachy Cakes
                        <span>and Deli Cafe</span>
                    </h1>

                    <p class="brand-description">
                        Create your customer account and make ordering
                        from Peachy Cakes & Deli Cafe easier.
                    </p>

                </div>


                <ul class="brand-list">

                    <li>
                        Order cakes and deli plates ahead
                    </li>

                    <li>
                        Save your pick-up details
                    </li>

                    <li>
                        Track your orders
                    </li>

                </ul>


            </aside>


            {{-- =================================================
                 REGISTRATION FORM
            ================================================== --}}

            <section class="form-panel">

                <h2>
                    New account
                </h2>

                <p class="subtitle">
                    Come on and join us — it only takes a minute.
                </p>


                @if ($errors->any())

                    <div class="alert">

                        <ul>

                            @foreach ($errors->all() as $error)

                                <li>
                                    {{ $error }}
                                </li>

                            @endforeach

                        </ul>

                    </div>

                @endif


                @if (session('error'))

                    <div class="alert">
                        {{ session('error') }}
                    </div>

                @endif


                <form
                    id="registerForm"
                    method="POST"
                    action="{{ route('customer.register.post') }}"
                >

                    @csrf


                    <div class="field-grid">


                        {{-- Name --}}
                        <label class="field full">

                            <span class="field-label">
                                Name
                                <i class="bi bi-check-lg field-valid-icon" aria-hidden="true"></i>
                            </span>

                            <input
                                type="text"
                                name="name"
                                value="{{ old('name') }}"
                                required
                                autofocus
                                autocomplete="name"
                                class="@error('name') is-invalid @enderror"
                            >

                            @error('name')

                                <span class="error-text">
                                    {{ $message }}
                                </span>

                            @enderror

                        </label>


                        {{-- Email --}}
                        <label class="field full">

                            <span class="field-label">
                                Email address
                                <i class="bi bi-check-lg field-valid-icon" aria-hidden="true"></i>
                            </span>

                            <input
                                type="email"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autocomplete="email"
                                class="@error('email') is-invalid @enderror"
                            >

                            @error('email')

                                <span class="error-text">
                                    {{ $message }}
                                </span>

                            @enderror

                        </label>


                        {{-- Password --}}
                        <label class="field">

                            <span class="field-label">
                                Password
                                <i class="bi bi-check-lg field-valid-icon" aria-hidden="true"></i>
                            </span>

                            <input
                                type="password"
                                name="password"
                                required
                                autocomplete="new-password"
                                class="@error('password') is-invalid @enderror"
                            >

                            @error('password')

                                <span class="error-text">
                                    {{ $message }}
                                </span>

                            @enderror

                        </label>


                        {{-- Confirm Password --}}
                        <label class="field">

                            <span class="field-label">
                                Confirm password
                                <i class="bi bi-check-lg field-valid-icon" aria-hidden="true"></i>
                            </span>

                            <input
                                type="password"
                                name="password_confirmation"
                                required
                                autocomplete="new-password"
                                class="@error('password_confirmation') is-invalid @enderror"
                            >

                            @error('password_confirmation')

                                <span class="error-text">
                                    {{ $message }}
                                </span>

                            @enderror

                        </label>


                        {{-- Contact Number --}}
                        <label class="field full">

                            <span class="field-label">
                                Contact number
                                <i class="bi bi-check-lg field-valid-icon" aria-hidden="true"></i>
                            </span>

                            <input
                                type="text"
                                name="contact_number"
                                value="{{ old('contact_number') }}"
                                required
                                inputmode="numeric"
                                autocomplete="tel"
                                class="@error('contact_number') is-invalid @enderror"
                            >

                            @error('contact_number')

                                <span class="error-text">
                                    {{ $message }}
                                </span>

                            @else

                                <span class="hint">
                                    Numbers only (10–13 digits)
                                </span>

                            @enderror

                        </label>


                        {{-- Address --}}
                        <label class="field full">

                            <span class="field-label">
                                Address
                            </span>

                            <input
                                type="text"
                                name="address"
                                value="{{ old('address') }}"
                                required
                                autocomplete="street-address"
                                class="@error('address') is-invalid @enderror"
                            >

                            @error('address')

                                <span class="error-text">
                                    {{ $message }}
                                </span>

                            @enderror

                        </label>

                    </div>


                    {{-- Terms link --}}
                    <p class="tos">
                        <a href="#termsModal">
                            View Terms of Service
                        </a>
                    </p>


                    {{-- Terms checkbox --}}
                    <label class="terms">

                        <input
                            type="checkbox"
                            id="agreeTerms"
                            name="terms"
                            value="1"
                            required
                            {{ old('terms') ? 'checked' : '' }}
                        >

                        <span>
                            I confirm that I have read and accept the
                            <a href="#termsModal">
                                terms and conditions and privacy policy
                            </a>
                        </span>

                    </label>


                    @error('terms')

                        <p class="error-text">
                            {{ $message }}
                        </p>

                    @enderror


                    <button
                        type="submit"
                        class="btn-submit"
                    >
                        Create account
                    </button>

                </form>


                {{-- Sign In --}}
                <p class="signin">

                    Have an account?

                    <a href="{{ route('customer.login') }}">
                        Sign in
                    </a>

                </p>

            </section>

        </section>

    </main>


    {{-- =====================================================
         TERMS OF SERVICE MODAL
    ====================================================== --}}

    <div
        class="modal"
        id="termsModal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="termsTitle"
    >

        <div class="modal-card">

            <div class="modal-head">

                <p class="eyebrow">
                    Peachy Cakes &amp; Deli Cafe
                </p>

                <h3 id="termsTitle">
                    Terms of Service
                </h3>

            </div>


            <div class="modal-body">

                <h4>
                    1. Your account
                </h4>

                <p>
                    You agree to provide accurate details when creating
                    an account and to keep your password confidential.
                    You are responsible for all orders placed through
                    your account.
                </p>


                <h4>
                    2. Orders and pick-up
                </h4>

                <ul>

                    <li>
                        Cake and deli orders are confirmed once we
                        contact you at the number you provide.
                    </li>

                    <li>
                        Custom cakes require advance notice; pick-up
                        times follow counter hours (7am – 9pm).
                    </li>

                    <li>
                        Unclaimed orders may be released after the end
                        of the pick-up day.
                    </li>

                </ul>


                <h4>
                    3. Payments and cancellations
                </h4>

                <p>
                    Deposits for custom orders are non-refundable once
                    preparation has started. Cancellations made before
                    preparation begins may be refunded or rescheduled.
                </p>


                <h4>
                    4. Food safety and allergens
                </h4>

                <p>
                    Our products are prepared in a kitchen that handles
                    nuts, dairy, eggs, gluten and other allergens.
                    Please tell us about allergies before ordering.
                </p>


                <h4>
                    5. Privacy policy
                </h4>

                <p>
                    We collect your name, email, contact number and
                    address only to process orders and to contact you
                    about them. We do not sell your information to
                    third parties.
                </p>


                <h4>
                    6. Changes to these terms
                </h4>

                <p>
                    We may update these terms from time to time.
                    Continued use of your account means you accept
                    the updated terms.
                </p>

            </div>


            <div class="modal-foot">

                <a
                    href="#"
                    class="btn-ghost"
                >
                    Close
                </a>

                <button
                    type="button"
                    class="btn-accept"
                    onclick="acceptTerms()"
                >
                    Accept &amp; Continue
                </button>

            </div>

        </div>

    </div>


    <script>

        function acceptTerms() {

            var checkbox =
                document.getElementById('agreeTerms');

            if (checkbox) {
                checkbox.checked = true;
            }

            /*
             * Close the modal for good.
             *
             * The modal is shown purely by CSS — the rule is .modal:target.
             * The old handler cleared the "#termsModal" fragment with
             * history.replaceState(), which does NOT clear :target, so the modal
             * stayed matched; the inline-style toggle that followed then let it
             * snap straight back open on a short timer — the reported "loop".
             *
             * Pointing the fragment at an id that matches nothing clears :target
             * reliably (and causes no scroll jump, since no element owns it). A
             * final replaceState tidies the throwaway fragment out of the URL.
             */
            window.location.hash = 'terms-accepted';

            history.replaceState(
                null,
                '',
                window.location.pathname +
                window.location.search
            );
        }

    </script>

    <script>

        /*
         * Real-time field feedback for the registration form.
         *
         * Each check mirrors EXACTLY the rule AuthController::register()
         * enforces on submit (see app/Http/Controllers/Customer/AuthController
         * .php and App\Support\PasswordPolicy) — no extra business rule is
         * introduced here; this only surfaces the existing rules as-you-type /
         * on-blur instead of only after a round-trip. Email uniqueness and the
         * "terms accepted" rule stay server-side (uniqueness can't be checked
         * here; the checkbox is its own affordance).
         */
        (function () {

            var form = document.getElementById('registerForm');
            if (!form) return;

            var pw = form.querySelector('input[name="password"]');

            // Same as PasswordPolicy::rule(): >= 8 chars, mixed case, a number
            // and a symbol.
            function passwordOk(v) {
                return v.length >= {{ \App\Support\PasswordPolicy::MIN_LENGTH }}
                    && /[a-z]/.test(v)
                    && /[A-Z]/.test(v)
                    && /[0-9]/.test(v)
                    && /[^A-Za-z0-9]/.test(v);
            }

            var rules = {
                // required|string|max:255
                name: function (v) { return v.trim().length > 0 && v.length <= 255; },
                // required|email  (format only — uniqueness is server-side)
                email: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); },
                // required + PasswordPolicy::rule()
                password: passwordOk,
                // 'confirmed' — must equal the password field
                password_confirmation: function (v) { return v.length > 0 && !!pw && v === pw.value; },
                // required|numeric|digits_between:10,13
                contact_number: function (v) { return /^[0-9]{10,13}$/.test(v); }
            };

            // showInvalid: only true on blur / submit-style checks. While the
            // user is still typing we confirm a pass (green tick) but never
            // flash the red invalid state at a half-finished value — that stays
            // the server's on-submit concern, mirrored here only on blur.
            function apply(input, name, showInvalid) {
                var field = input.closest('.field');
                if (input.value === '') {
                    input.classList.remove('is-valid', 'is-invalid');
                    if (field) field.classList.remove('is-valid');
                    return;
                }
                var ok = rules[name](input.value);
                input.classList.toggle('is-valid', ok);
                if (field) field.classList.toggle('is-valid', ok);
                if (ok) {
                    input.classList.remove('is-invalid');
                } else if (showInvalid) {
                    input.classList.add('is-invalid');
                }
            }

            Object.keys(rules).forEach(function (name) {
                var input = form.querySelector('input[name="' + name + '"]');
                if (!input) return;
                input.addEventListener('input', function () { apply(input, name, false); });
                input.addEventListener('blur', function () { apply(input, name, true); });
            });

            // Re-run the confirmation check whenever the password itself changes.
            var pwc = form.querySelector('input[name="password_confirmation"]');
            if (pw && pwc) {
                pw.addEventListener('input', function () {
                    if (pwc.value !== '') apply(pwc, 'password_confirmation', false);
                });
            }

        })();

    </script>

</body>

</html>

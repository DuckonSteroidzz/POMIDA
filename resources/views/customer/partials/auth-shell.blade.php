<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('page-title', 'Account Recovery') | Peachy Cakes & Deli Cafe</title>


    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

    {{-- Bootstrap Icons — used for the show/hide password toggle
         (same CDN as customer/login.blade.php). --}}
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

        h1,
        h2,
        h3 {
            font-family: "Fraunces", Georgia, serif;
        }

        a {
            text-decoration: none;
        }

        /* =====================================================
           SAME WELCOME.BLADE.PHP BACKGROUND
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

        .login-page {
            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: clamp(1rem, 4vw, 2.5rem);

            position: relative;

            z-index: 3;
        }

        /* =====================================================
           LOGIN CARD
        ===================================================== */

        .login-card {
            position: relative;
            z-index: 3;

            width: 100%;
            max-width: 460px;

            background: rgba(255, 253, 249, 0.97);

            border: 1px solid rgba(244, 132, 95, 0.4);

            border-radius: clamp(1.5rem, 4vw, 2rem);

            padding: clamp(2rem, 6vw, 3.25rem);

            box-shadow:
                0 30px 70px -30px rgba(90, 41, 32, 0.55);

            backdrop-filter: blur(10px);
        }

        /* =====================================================
           BRAND HEADER
        ===================================================== */

        .brand-section {
            text-align: center;
            margin-bottom: 2rem;
        }

        .eyebrow {
            font-size: 0.65rem;
            font-weight: 700;

            letter-spacing: 0.3em;
            text-transform: uppercase;

            color: var(--peach-soft);
        }

        .brand-title {
            margin-top: 0.75rem;

            font-family: "Fraunces", Georgia, serif;

            font-size: clamp(2.1rem, 8vw, 3rem);

            font-weight: 600;

            line-height: 1.05;

            letter-spacing: -0.025em;

            color: var(--deep-red);
        }

        .brand-title span {
            display: block;

            margin-top: 0.25rem;

            font-size: clamp(1.35rem, 5vw, 1.8rem);

            color: var(--peach-soft);
        }

        .page-subtitle {
            margin-top: 1rem;

            color: var(--muted);

            font-size: 0.95rem;

            line-height: 1.6;
        }

        /* =====================================================
           ALERTS
        ===================================================== */

        .alert {
            border-radius: 0.9rem;

            padding: 0.8rem 1rem;

            margin-bottom: 1.25rem;

            font-size: 0.875rem;

            line-height: 1.5;
        }

        .alert-danger {
            color: var(--deep-red);

            background: rgba(192, 57, 43, 0.08);

            border: 1px solid rgba(192, 57, 43, 0.2);
        }

        .alert-success {
            color: #276749;

            background: rgba(39, 103, 73, 0.08);

            border: 1px solid rgba(39, 103, 73, 0.2);
        }

        .alert-warning {
            color: #7A4B00;

            background: rgba(240, 173, 78, 0.14);

            border: 1px solid rgba(240, 173, 78, 0.4);
        }

        .alert code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        .alert ul {
            padding-left: 1.2rem;
        }

        /* =====================================================
           FORM
        ===================================================== */

        .auth-title {
            margin-bottom: 1.25rem;

            font-family: "Fraunces", Georgia, serif;

            font-size: 1.4rem;

            font-weight: 600;

            text-align: center;

            color: var(--deep-red);
        }

        .form-group {
            margin-bottom: 1.15rem;
        }

        .form-label {
            display: block;

            margin-bottom: 0.45rem;

            font-size: 0.8rem;

            font-weight: 700;

            color: var(--text);
        }

        .form-control {
            width: 100%;

            min-height: 48px;

            padding:
                0.75rem
                0.9rem;

            border:
                1px solid
                rgba(138, 106, 97, 0.25);

            border-radius: 0.8rem;

            background: #fff;

            color: var(--text);

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.95rem;

            outline: none;

            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        .form-control::placeholder {
            color: #B39A93;
        }

        .form-control:focus {
            border-color: var(--peach);

            box-shadow:
                0 0 0 3px
                rgba(244, 132, 95, 0.15);
        }

        .form-control.is-invalid {
            border-color: var(--terracotta);
        }

        /* =====================================================
           SHOW / HIDE PASSWORD
        ===================================================== */

        .password-wrapper {
            position: relative;
        }

        /* Leave room for the eye button so long passwords never sit under it. */
        .password-wrapper .form-control {
            padding-right: 3rem;
        }

        .password-toggle {
            position: absolute;

            top: 0;
            right: 0;

            height: 100%;
            width: 3rem;

            display: flex;
            align-items: center;
            justify-content: center;

            border: none;
            background: transparent;

            color: var(--muted);

            font-size: 1.05rem;
            line-height: 1;

            cursor: pointer;

            border-radius: 0 0.8rem 0.8rem 0;

            transition: color 0.2s ease;
        }

        .password-toggle:hover {
            color: var(--deep-red);
        }

        .password-toggle:focus-visible {
            outline: 2px solid var(--peach);
            outline-offset: -2px;
        }

        .invalid-feedback {
            margin-top: 0.35rem;

            color: var(--terracotta);

            font-size: 0.78rem;
        }

        /* =====================================================
           OTP INPUT
        ===================================================== */

        .otp-wrapper {
            display: flex;

            justify-content: center;

            gap: 0.5rem;

            margin-bottom: 1.25rem;
        }

        .otp-input {
            width: 44px;
            height: 52px;

            text-align: center;

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 1.2rem;
            font-weight: 700;

            color: var(--text);

            border: 1px solid rgba(138, 106, 97, 0.25);

            border-radius: 0.8rem;

            background: #fff;

            outline: none;

            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        .otp-input:focus {
            border-color: var(--peach);

            box-shadow:
                0 0 0 3px
                rgba(244, 132, 95, 0.15);
        }

        /* =====================================================
           LINKS + BUTTON
        ===================================================== */

        .auth-link {
            color: var(--deep-red);

            font-size: 0.8rem;

            font-weight: 700;

            text-decoration: underline;

            text-underline-offset: 3px;
        }

        .auth-link:hover {
            color: var(--terracotta);
        }

        .hint {
            margin-bottom: 1.25rem;

            color: var(--muted);

            font-size: 0.85rem;

            line-height: 1.6;

            text-align: center;
        }

        .btn-main {
            width: 100%;

            min-height: 52px;

            border: none;

            border-radius: 999px;

            background: var(--deep-red);

            color: #fff;

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.95rem;

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

        .btn-main:hover {
            background: var(--terracotta);

            transform: translateY(-2px);

            box-shadow:
                0 12px 24px
                rgba(139, 26, 26, 0.3);
        }

        .btn-main:active {
            transform: translateY(0);
        }

        /* =====================================================
           BACK-TO-SIGN-IN FOOTER
        ===================================================== */

        .signup {
            margin-top: 1.5rem;

            padding-top: 1.25rem;

            border-top:
                1px solid
                rgba(139, 26, 26, 0.12);

            text-align: center;

            color: var(--muted);

            font-size: 0.85rem;
        }

        .signup .auth-link {
            margin-left: 0.25rem;
        }

        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 480px) {

            .login-page {
                padding: 1rem;
            }

            .login-card {
                padding: 2rem 1.35rem;

                border-radius: 1.5rem;
            }

            .brand-title {
                font-size: 2.25rem;
            }

            .brand-title span {
                font-size: 1.45rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .btn-main {
                transition: none;
            }

            .btn-main:hover {
                transform: none;
            }
        }
    </style>

    @stack('styles')
</head>

<body>

    {{-- =====================================================
         SAME BACKGROUND AS WELCOME.BLADE.PHP / login.blade.php
    ====================================================== --}}

    <img
        src="{{ asset('images/hero-cafe.png') }}"
        alt=""
        class="background-image"
        onerror="this.style.display='none';"
    >

    <div class="background-overlay"></div>

    <div class="background-glow"></div>


    <main class="login-page">

        <section class="login-card">

            {{-- Brand --}}
            <div class="brand-section">

                <p class="eyebrow">
                    Bakery · Deli · Cafe
                </p>

                <h1 class="brand-title">
                    Peachy Cakes
                    <span>and Deli Cafe</span>
                </h1>

                <p class="page-subtitle">
                    @yield('page-subtitle', 'Hurry Up and Explore!')
                </p>

            </div>


            {{-- Error / Success Alerts --}}
            @if (session('error'))

                <div class="alert alert-danger">
                    {{ session('error') }}
                </div>

            @endif

            @if ($errors->any())

                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>

            @endif

            @if (session('success'))

                <div class="alert alert-success">
                    {{ session('success') }}
                </div>

            @endif

            {{-- Development helper. Only rendered while MAIL_MAILER=log in the
                 local environment, because nothing is actually delivered then.
                 Configure a real SMTP mailer and this block disappears. --}}
            @if (session('dev_reset_code'))

                <div class="alert alert-warning">
                    <strong>Development mode:</strong> email is not really being
                    sent (<code>MAIL_MAILER=log</code>), so your code is
                    <strong>{{ session('dev_reset_code') }}</strong>.
                </div>

            @endif


            <p class="auth-title">@yield('form-title')</p>

            @yield('content')


            <p class="signup">
                Back to Sign In?
                <a href="{{ route('customer.login') }}" class="auth-link">Sign In</a>
            </p>

        </section>

    </main>

    @stack('scripts')

</body>

</html>

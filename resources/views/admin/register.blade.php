<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register | Peachy Cakes & Deli Cafe</title>


    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

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

        /* =====================================================
           SAME WELCOME.BLADE.PHP BACKGROUND
        ====================================================== */

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

            z-index: 2;

            pointer-events: none;
        }

        /* =====================================================
           PAGE
        ====================================================== */

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
        ====================================================== */

        .register-card {
            width: 100%;
            max-width: 520px;

            background: rgba(255, 253, 249, 0.97);

            border: 1px solid rgba(244, 132, 95, 0.4);

            border-radius: clamp(1.5rem, 4vw, 2rem);

            padding: clamp(1.8rem, 5vw, 3rem);

            box-shadow:
                0 30px 70px -30px rgba(90, 41, 32, 0.55);

            backdrop-filter: blur(10px);
        }

        /* =====================================================
           HEADER
        ====================================================== */

        .header {
            text-align: center;
            margin-bottom: 1.8rem;
        }

        .eyebrow {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--peach-soft);
        }

        .title {
            margin-top: 0.7rem;

            font-family: "Fraunces", Georgia, serif;

            font-size: clamp(2rem, 7vw, 2.7rem);

            font-weight: 600;
            line-height: 1.05;

            letter-spacing: -0.025em;

            color: var(--deep-red);
        }

        .title span {
            display: block;

            margin-top: 0.25rem;

            font-size: clamp(1.25rem, 4.5vw, 1.65rem);

            color: var(--peach-soft);
        }

        .subtitle {
            margin-top: 0.8rem;

            color: var(--muted);

            font-size: 0.88rem;
            line-height: 1.5;
        }

        /* =====================================================
           ALERTS
        ====================================================== */

        .alert {
            margin-bottom: 1.1rem;

            padding: 0.8rem 1rem;

            border-radius: 0.85rem;

            font-size: 0.82rem;
            line-height: 1.5;
        }

        .alert-error {
            color: var(--deep-red);

            background: rgba(192, 57, 43, 0.08);

            border: 1px solid rgba(192, 57, 43, 0.2);
        }

        .alert-success {
            color: #276749;

            background: rgba(39, 103, 73, 0.08);

            border: 1px solid rgba(39, 103, 73, 0.2);
        }

        .alert ul {
            padding-left: 1.2rem;
        }

        /* =====================================================
           FORM
        ====================================================== */

        .form-title {
            margin-bottom: 1.15rem;

            color: var(--deep-red);

            font-family: "Fraunces", Georgia, serif;

            font-size: 1.35rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;

            margin-bottom: 0.4rem;

            color: var(--text);

            font-size: 0.76rem;
            font-weight: 700;
        }

        .form-control {
            width: 100%;

            min-height: 46px;

            padding: 0.7rem 0.9rem;

            border:
                1px solid
                rgba(138, 106, 97, 0.25);

            border-radius: 0.8rem;

            background: #fff;

            color: var(--text);

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.92rem;

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

        .error-text {
            display: block;

            margin-top: 0.3rem;

            color: var(--terracotta);

            font-size: 0.74rem;
        }

        /* =====================================================
           ACCESS CODE
        ====================================================== */

        .access-code-box {
            margin-bottom: 1rem;

            padding: 0.9rem;

            border-radius: 0.9rem;

            background: rgba(253, 232, 222, 0.65);

            border:
                1px solid
                rgba(244, 132, 95, 0.22);
        }

        .access-code-label {
            display: block;

            margin-bottom: 0.3rem;

            color: var(--deep-red);

            font-size: 0.76rem;
            font-weight: 700;
        }

        .access-code-help {
            margin-bottom: 0.7rem;

            color: var(--muted);

            font-size: 0.72rem;
            line-height: 1.45;
        }

        /* =====================================================
           REGISTER BUTTON
        ====================================================== */

        .btn-register {
            width: 100%;

            min-height: 50px;

            margin-top: 0.35rem;

            border: none;

            border-radius: 999px;

            background: var(--deep-red);

            color: #fff;

            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            font-size: 0.93rem;
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

        .btn-register:hover {
            background: var(--terracotta);

            transform: translateY(-2px);

            box-shadow:
                0 12px 24px
                rgba(139, 26, 26, 0.3);
        }

        .btn-register:active {
            transform: translateY(0);
        }

        /* =====================================================
           LOGIN LINK
        ====================================================== */

        .login-link {
            margin-top: 1.35rem;

            padding-top: 1.15rem;

            border-top:
                1px solid
                rgba(139, 26, 26, 0.12);

            text-align: center;

            color: var(--muted);

            font-size: 0.8rem;
        }

        .login-link a {
            margin-left: 0.25rem;

            color: var(--deep-red);

            font-weight: 700;

            text-decoration: underline;

            text-underline-offset: 3px;
        }

        .login-link a:hover {
            color: var(--terracotta);
        }

        /* =====================================================
           MOBILE
        ====================================================== */

        @media (max-width: 480px) {

            .register-page {
                padding: 1rem;
            }

            .register-card {
                padding: 1.8rem 1.25rem;

                border-radius: 1.5rem;
            }

            .title {
                font-size: 2.1rem;
            }

            .title span {
                font-size: 1.35rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .btn-register {
                transition: none;
            }

            .btn-register:hover {
                transform: none;
            }
        }
    </style>
</head>

<body>

    {{-- =====================================================
         SAME BACKGROUND AS WELCOME.BLADE.PHP
    ====================================================== --}}

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

            <header class="header">

                <p class="eyebrow">
                    Bakery · Deli · Cafe
                </p>

                <h1 class="title">
                    Peachy Cakes
                    <span>and Deli Cafe</span>
                </h1>

                <p class="subtitle">
                    Become Part of the Team!<br>Ask your manager for the access code to sign up.
                </p>

            </header>


            {{-- Laravel validation errors --}}
            @if ($errors->any())

                <div class="alert alert-error">

                    <ul>

                        @foreach ($errors->all() as $error)

                            <li>
                                {{ $error }}
                            </li>

                        @endforeach

                    </ul>

                </div>

            @endif


            {{-- Laravel success message --}}
            @if (session('success'))

                <div class="alert alert-success">
                    {{ session('success') }}
                </div>

            @endif


            <h2 class="form-title">
                Create Account
            </h2>


            {{-- IMPORTANT:
                 This matches AdminAuthController::register()
                 and the existing admin.register.post route. --}}
            <form
                action="{{ route('admin.register.post') }}"
                method="POST"
            >

                @csrf


                {{-- Access Code --}}
                <div class="access-code-box">

                    <label
                        for="code"
                        class="access-code-label"
                    >
                        Access Code
                    </label>

                    <p class="access-code-help">
                        Enter the authorized access code provided by the system administrator.
                    </p>

                    <input
                        id="code"
                        type="text"
                        name="code"
                        value="{{ old('code') }}"
                        class="form-control @error('code') is-invalid @enderror"
                        autocomplete="off"
                        required
                    >

                    @error('code')

                        <span class="error-text">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- Username --}}
                <div class="form-group">

                    <label
                        for="username"
                        class="form-label"
                    >
                        Username
                    </label>

                    <input
                        id="username"
                        type="text"
                        name="username"
                        value="{{ old('username') }}"
                        class="form-control @error('username') is-invalid @enderror"
                        autocomplete="username"
                        required
                    >

                    @error('username')

                        <span class="error-text">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- Email --}}
                <div class="form-group">

                    <label
                        for="email"
                        class="form-label"
                    >
                        Email Address
                    </label>

                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        class="form-control @error('email') is-invalid @enderror"
                        autocomplete="email"
                        required
                    >

                    @error('email')

                        <span class="error-text">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- Password --}}
                <div class="form-group">

                    <label
                        for="password"
                        class="form-label"
                    >
                        Password
                    </label>

                    <input
                        id="password"
                        type="password"
                        name="password"
                        class="form-control @error('password') is-invalid @enderror"
                        autocomplete="new-password"
                        required
                    >

                    @error('password')

                        <span class="error-text">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- Confirm Password --}}
                <div class="form-group">

                    <label
                        for="password_confirmation"
                        class="form-label"
                    >
                        Confirm Password
                    </label>

                    <input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        class="form-control"
                        autocomplete="new-password"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="btn-register"
                >
                    Create Account
                </button>

            </form>


            <p class="login-link">

                Already have an account?

                <a href="{{ route('admin.login') }}">
                    Back to Login
                </a>

            </p>

        </section>

    </main>

</body>

</html>

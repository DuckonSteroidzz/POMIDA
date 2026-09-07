<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Login | Peachy Cakes & Deli Cafe</title>


    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

    {{-- Bootstrap Icons — used for the show/hide password toggle
         (same CDN as customer/layout.blade.php). --}}
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
            width: 100%;
            max-width: 470px;

            background: rgba(255, 253, 249, 0.97);

            border: 1px solid rgba(244, 132, 95, 0.4);

            border-radius: clamp(1.5rem, 4vw, 2rem);

            padding: clamp(2rem, 6vw, 3.25rem);

            box-shadow:
                0 30px 70px -30px rgba(90, 41, 32, 0.55);

            backdrop-filter: blur(10px);
        }

        /* =====================================================
           HEADER
        ===================================================== */

        .header {
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

        .title {
            margin-top: 0.75rem;

            font-family: "Fraunces", Georgia, serif;

            font-size: clamp(2.2rem, 8vw, 3rem);

            font-weight: 600;
            line-height: 1.05;

            letter-spacing: -0.025em;

            color: var(--deep-red);
        }

        .title span {
            display: block;

            margin-top: 0.3rem;

            font-size: clamp(1.35rem, 5vw, 1.8rem);

            color: var(--peach-soft);
        }

        .subtitle {
            margin-top: 0.9rem;

            color: var(--muted);

            font-size: 0.9rem;
            line-height: 1.55;
        }

        /* =====================================================
           ALERTS
        ===================================================== */

        .alert {
            margin-bottom: 1.25rem;

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
        ===================================================== */

        .form-title {
            margin-bottom: 1.2rem;

            color: var(--deep-red);

            font-family: "Fraunces", Georgia, serif;

            font-size: 1.4rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.15rem;
        }

        .form-label {
            display: block;

            margin-bottom: 0.45rem;

            color: var(--text);

            font-size: 0.78rem;
            font-weight: 700;
        }

        .form-control {
            width: 100%;

            min-height: 48px;

            padding: 0.75rem 0.9rem;

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

        /* =====================================================
           FORGOT PASSWORD LINK
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

        .error-text {
            display: block;

            margin-top: 0.35rem;

            color: var(--terracotta);

            font-size: 0.76rem;
        }

        /* =====================================================
           REMEMBER ME
        ===================================================== */

        .form-options {
            display: flex;

            align-items: center;
            justify-content: space-between;

            margin-bottom: 1.25rem;
        }

        .remember {
            display: flex;

            align-items: center;

            gap: 0.45rem;

            color: var(--muted);

            font-size: 0.8rem;

            cursor: pointer;
        }

        .remember input {
            width: 15px;
            height: 15px;

            accent-color: var(--deep-red);

            cursor: pointer;
        }

        /* =====================================================
           LOGIN BUTTON
        ===================================================== */

        .btn-login {
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

        .btn-login:hover {
            background: var(--terracotta);

            transform: translateY(-2px);

            box-shadow:
                0 12px 24px
                rgba(139, 26, 26, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        /* =====================================================
           ADMIN REGISTER LINK
        ===================================================== */

        /* First-run bootstrap callout. Only ever rendered on an
           installation with zero admin accounts. */
        .bootstrap-callout {
            margin-top: 1.5rem;
            padding: 1rem 1.1rem;
            border: 1px solid rgba(139, 26, 26, 0.18);
            border-radius: 14px;
            background: rgba(244, 132, 95, 0.08);
            text-align: left;
        }

        .bootstrap-callout-title {
            margin: 0 0 0.35rem;
            font-size: 0.9rem;
            font-weight: 800;
            color: #8B1A1A;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .bootstrap-callout-text {
            margin: 0 0 0.75rem;
            font-size: 0.78rem;
            line-height: 1.45;
            color: #6b4f48;
        }

        .bootstrap-callout-link {
            display: inline-block;
            padding: 0.55rem 1.1rem;
            border-radius: 10px;
            background: linear-gradient(135deg, #8B1A1A 0%, #C0392B 100%);
            color: #fff;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
        }

        .bootstrap-callout-link:hover { filter: brightness(1.08); }

        .register-link {
            margin-top: 1.5rem;

            padding-top: 1.25rem;

            border-top:
                1px solid
                rgba(139, 26, 26, 0.12);

            text-align: center;

            color: var(--muted);

            font-size: 0.82rem;
        }

        .register-link a {
            margin-left: 0.25rem;

            color: var(--deep-red);

            font-weight: 700;

            text-decoration: underline;

            text-underline-offset: 3px;
        }

        .register-link a:hover {
            color: var(--terracotta);
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

            .title {
                font-size: 2.25rem;
            }

            .title span {
                font-size: 1.45rem;
            }

            .background-glow {
                width: 400px;
                height: 400px;

                right: -25%;
                bottom: -15%;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .btn-login {
                transition: none;
            }

            .btn-login:hover {
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


    <main class="login-page">

        <section class="login-card">

            <header class="header">

                <p class="eyebrow">
                    Bakery · Deli · Cafe
                </p>

                <h1 class="title">
                    Peachy Cakes
                    <span>and Deli Cafe</span>
                </h1>

                <p class="subtitle">
                    Staff & Admin Portal
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

            {{-- IMPORTANT:
                 This keeps the existing admin login route. --}}
            <form
                action="{{ route('admin.login.post') }}"
                method="POST"
            >

                @csrf


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
                        type="text"
                        inputmode="email"
                        name="email"
                        value="{{ old('email') }}"
                        class="form-control @error('email') is-invalid @enderror"
                        autocomplete="username"
                        required
                        autofocus
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

                    <div class="password-wrapper">

                        <input
                            id="password"
                            type="password"
                            name="password"
                            class="form-control @error('password') is-invalid @enderror"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-password-toggle="password"
                            aria-label="Show password"
                            aria-pressed="false"
                        >
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>

                    </div>

                    @error('password')

                        <span class="error-text">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- Remember Me --}}
                <div class="form-options">

                    <label class="remember">

                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                            {{ old('remember') ? 'checked' : '' }}
                        >

                        <span>
                            Remember me
                        </span>

                    </label>


                    <a
                        href="{{ route('admin.forgot-password') }}"
                        class="auth-link"
                    >
                        Forgot Password?
                    </a>

                </div>


                <button
                    type="submit"
                    class="btn-login"
                >
                    Login
                </button>

            </form>


            {{-- Self-service registration removed: staff accounts are created
                 by an admin from Staff Accounts (/admin/users).

                 The ONE exception is a brand-new installation with no admin at
                 all, where there is nobody who could create anything. That link
                 appears below, and only then.

                 $canBootstrapAdmin comes from AdminBootstrap::isAvailable().
                 Hiding this is a convenience, NOT the control — the route
                 itself refuses once any admin exists, and refuses again inside
                 the creating transaction. --}}
            @if(!empty($canBootstrapAdmin))
                <div class="bootstrap-callout">
                    <p class="bootstrap-callout-title">
                        <i class="bi bi-stars" aria-hidden="true"></i>
                        First time setting this up?
                    </p>
                    <p class="bootstrap-callout-text">
                        This system has no administrator yet. Create the first one to get started —
                        this option disappears for good once it exists.
                    </p>
                    <a href="{{ route('admin.bootstrap') }}" class="bootstrap-callout-link">
                        Create Administrator Account
                    </a>
                </div>
            @else
                <p class="register-link">
                    Need on the crew? Ask an administrator to create your account.
                </p>
            @endif

        </section>

    </main>


    {{-- Show / hide password --}}
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {

            var field = document.getElementById(button.dataset.passwordToggle);
            var icon = button.querySelector('i');

            if (!field || !icon) {
                return;
            }

            button.addEventListener('click', function () {

                var revealed = field.type === 'text';

                field.type = revealed ? 'password' : 'text';

                icon.classList.toggle('bi-eye', revealed);
                icon.classList.toggle('bi-eye-slash', !revealed);

                button.setAttribute('aria-pressed', String(!revealed));
                button.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');

                field.focus();
            });
        });
    </script>

</body>

</html>

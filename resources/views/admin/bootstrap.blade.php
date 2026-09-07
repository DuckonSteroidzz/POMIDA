{{--
    First-run administrator setup.

    Reachable ONLY while this installation has zero admin accounts. The
    controller re-checks that on both the GET and the POST, and
    AdminBootstrap::create() re-checks a third time inside the transaction that
    creates the account — this page never being linked is a convenience, not
    the control.

    Styling deliberately mirrors admin/login.blade.php: it is the same portal
    and this is the only other page a brand-new installation ever shows.
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>First-Time Setup | Peachy Cakes &amp; Deli Cafe</title>

    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')
    <link href="/vendor/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --deep-red: #8B1A1A;
            --terracotta: #C0392B;
            --peach: #F4845F;
            --peach-soft: #E88A6E;
            --cream: #FFFDF9;
            --text: #5A2920;
            --muted: #8A6A61;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { width: 100%; min-height: 100%; }

        body {
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--text);
            min-height: 100vh;
            background: linear-gradient(135deg, #F8D7B0 0%, #F6B49B 50%, #EF8585 100%);
            overflow-x: hidden;
        }

        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(1rem, 4vw, 2.5rem);
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: rgba(255, 253, 249, 0.97);
            border: 1px solid rgba(244, 132, 95, 0.4);
            border-radius: clamp(1.5rem, 4vw, 2rem);
            padding: clamp(2rem, 6vw, 3rem);
            box-shadow: 0 30px 70px -30px rgba(90, 41, 32, 0.55);
        }

        .eyebrow {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: var(--peach-soft);
            text-align: center;
        }

        .title {
            margin-top: 0.6rem;
            font-family: "Fraunces", Georgia, serif;
            font-size: clamp(1.8rem, 6vw, 2.4rem);
            font-weight: 600;
            line-height: 1.1;
            color: var(--deep-red);
            text-align: center;
        }

        .subtitle {
            margin: 0.9rem 0 1.6rem;
            color: var(--muted);
            font-size: 0.88rem;
            line-height: 1.55;
            text-align: center;
        }

        .notice {
            margin-bottom: 1.5rem;
            padding: 0.85rem 1rem;
            border-radius: 0.85rem;
            background: rgba(244, 132, 95, 0.1);
            border: 1px solid rgba(139, 26, 26, 0.16);
            font-size: 0.78rem;
            line-height: 1.5;
            color: #6b4f48;
        }

        .alert {
            margin-bottom: 1.25rem;
            padding: 0.8rem 1rem;
            border-radius: 0.85rem;
            font-size: 0.82rem;
            line-height: 1.5;
            color: var(--deep-red);
            background: rgba(192, 57, 43, 0.08);
            border: 1px solid rgba(192, 57, 43, 0.2);
        }

        .alert ul { padding-left: 1.2rem; }

        .form-group { margin-bottom: 1.15rem; }

        .form-label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .form-control {
            width: 100%;
            min-height: 48px;
            padding: 0.75rem 0.9rem;
            border: 1px solid rgba(138, 106, 97, 0.25);
            border-radius: 0.8rem;
            background: #fff;
            color: var(--text);
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.95rem;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--peach);
            box-shadow: 0 0 0 3px rgba(244, 132, 95, 0.15);
        }

        .hint {
            margin-top: 0.4rem;
            font-size: 0.72rem;
            color: var(--muted);
            line-height: 1.45;
        }

        .btn {
            width: 100%;
            min-height: 50px;
            margin-top: 0.5rem;
            border: 0;
            border-radius: 0.85rem;
            background: linear-gradient(135deg, var(--deep-red) 0%, var(--terracotta) 100%);
            color: #fff;
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn:hover { filter: brightness(1.08); }

        .back {
            display: block;
            margin-top: 1.25rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--muted);
        }

        .back a { color: var(--deep-red); font-weight: 700; }
    </style>
</head>

<body>
    <div class="page">
        <div class="card">

            <p class="eyebrow">First-Time Setup</p>
            <h1 class="title">Create Administrator</h1>
            <p class="subtitle">
                This system has no administrator yet. Create the first one to get started.
            </p>

            <div class="notice">
                <strong>This happens once.</strong> As soon as this account exists, this page
                is closed permanently — nobody can use it again, and new staff accounts are
                created from inside the admin panel instead. Choose a password you will not lose.
            </div>

            @if($errors->any())
                <div class="alert">
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('admin.bootstrap.store') }}" method="POST">
                @csrf

                <div class="form-group">
                    <label class="form-label" for="name">Full name</label>
                    <input type="text" id="name" name="name" class="form-control"
                        value="{{ old('name') }}" required maxlength="255"
                        autocomplete="name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email address</label>
                    <input type="email" id="email" name="email" class="form-control"
                        value="{{ old('email') }}" required maxlength="255"
                        autocomplete="username">
                    <p class="hint">You will sign in with this address.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="pw-field">
                        <input type="password" id="password" name="password" class="form-control"
                            required autocomplete="new-password">
                        <button type="button" class="js-pw-toggle" data-target="password"
                            aria-label="Show password" aria-pressed="false" title="Show password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <p class="hint">{{ \App\Support\PasswordPolicy::describe() }}</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password_confirmation">Confirm password</label>
                    <div class="pw-field">
                        <input type="password" id="password_confirmation" name="password_confirmation"
                            class="form-control" required autocomplete="new-password">
                        <button type="button" class="js-pw-toggle" data-target="password_confirmation"
                            aria-label="Show password" aria-pressed="false" title="Show password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn">Create Administrator Account</button>
            </form>

            <p class="back">
                Already set up? <a href="{{ route('admin.login') }}">Go to sign in</a>
            </p>

        </div>
    </div>

    {{-- Same show/hide toggle every other password field in the admin portal
         uses. Purely front-end; it reveals only what is being typed. --}}
    @include('admin.partials.password-toggle')
</body>

</html>

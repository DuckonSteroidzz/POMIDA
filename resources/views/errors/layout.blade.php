{{--
    Shared shell for every HTTP error page.

    Deliberately self-contained: no @extends of customer/layout or
    admin layouts, and no database or session lookups. An error page
    that itself depends on app state can fail while rendering, and the
    user then gets the blank white page this file exists to prevent.
    Fonts are linked but the stack degrades to system fonts offline.
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') — Peachy Cakes &amp; Deli Cafe</title>

    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

    <style>
        :root {
            --deep-red: #8B1A1A;
            --terracotta: #C0392B;
            --peach: #F4845F;
            --light-peach: #FDE8DE;
            --cream: #FFFDF9;
            --text: #5A2920;
            --muted: #8A6A61;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Karla", system-ui, -apple-system, "Segoe UI", sans-serif;
            color: var(--text);
            background: linear-gradient(135deg, #F8D7B0 0%, #F6B49B 50%, #EF8585 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            background: var(--cream);
            border-radius: 24px;
            box-shadow: 0 18px 45px rgba(139, 26, 26, .18);
            max-width: 520px;
            width: 100%;
            padding: 44px 36px 38px;
            text-align: center;
        }

        .emoji { font-size: 54px; line-height: 1; }

        .code {
            font-family: "Karla", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--muted);
            margin-top: 18px;
        }

        h1 {
            font-family: "Fraunces", Georgia, serif;
            font-size: 30px;
            font-weight: 700;
            color: var(--deep-red);
            margin: 8px 0 14px;
            line-height: 1.2;
        }

        p { font-size: 16px; line-height: 1.6; color: var(--text); }
        p + p { margin-top: 12px; }

        .actions {
            margin-top: 28px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 15px;
            text-decoration: none;
            border: 2px solid var(--peach);
            cursor: pointer;
        }

        .btn-primary { background: var(--peach); color: #fff; }
        .btn-ghost { background: transparent; color: var(--terracotta); }
        .btn:hover { filter: brightness(.95); }

        .ref {
            margin-top: 26px;
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>

<body>
    <main class="card">
        <div class="emoji">@yield('emoji', '🍑')</div>
        <div class="code">Error @yield('code')</div>
        <h1>@yield('title')</h1>
        @yield('message')

        {{--
            Context-aware exits. A dine-in customer who hits an error mid-visit
            gets a way back to their order or their table's menu, not only the
            landing page. App\Support\ErrorPageContext reads nothing but the
            session, does no database work, and swallows its own failures — if
            anything at all goes wrong it returns exactly the single
            "Back to home" link this page used to hard-code, so the page still
            renders when the database is what broke.
        --}}
        <div class="actions">
            @yield('extra_actions')

            @foreach(\App\Support\ErrorPageContext::links() as $link)
                <a class="btn {{ $link['primary'] ? 'btn-primary' : 'btn-ghost' }}"
                    href="{{ $link['url'] }}">{{ $link['label'] }}</a>
            @endforeach
        </div>

        @hasSection('ref')
            <p class="ref">@yield('ref')</p>
        @endif
    </main>
</body>

</html>

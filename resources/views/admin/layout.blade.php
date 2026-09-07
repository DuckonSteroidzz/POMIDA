<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Peachy Admin')</title>

    {{-- Live CSRF token + the 419 handler for every admin page. --}}
    @include('partials.session-guard')

    {{--
        Sidebar icon flicker, investigated 2026-09-02.

        This app does full server-side page reloads (grepped for Livewire and
        Alpine across the whole admin tree — neither is used anywhere, and the
        sidebar is plain server-rendered <a href> links, no JS re-render is
        possible), so a real repaint on every navigation is inherent and was
        already called out as expected in the earlier caching pass. What made
        it read as icons specifically "disappearing" rather than an ordinary
        repaint is bootstrap-icons.css's `font-display: block`, which hides
        icon glyphs (the nav LABELS still render — only the icons go blank)
        until the icon font is ready, for up to ~3s if it is not.

        The previous pass gave that font a long-lived Cache-Control header in
        public/.htaccess, verified this session against a REAL Apache
        instance (not just php artisan serve, which never applies .htaccess
        at all and was the wrong thing to trust last time) — confirmed 200
        with `Cache-Control: public, max-age=31536000, immutable` on both
        bootstrap-icons.css and its .woff2. That removes the network re-fetch
        cost on every click, which was the dominant cause.

        What is left, and what this preload targets: even from a warm cache,
        the browser only starts loading a font AFTER it has fetched and
        parsed the CSS that declares the @font-face and discovered an element
        that needs it — two steps in series. A preload hint tells the browser
        up front "this font will be needed", so the fetch (cache hit or not)
        starts in parallel with CSS parsing instead of after it, shortening
        the remaining window during which font-display:block can hide the
        glyphs. `crossorigin` is required on a font preload even for a
        same-origin file — the Fetch spec always uses CORS mode for fonts,
        and Chrome silently double-fetches without it.

        Not needed for the icon SET generally, only the WOFF2 — that is the
        only format modern browsers actually request (bootstrap-icons.css
        lists woff2 first), so preloading the woff would fetch a file no
        browser ends up using.
    --}}
    {{--
        The href here must match the URL bootstrap-icons.css actually requests
        byte-for-byte, query string included (it loads
        ./fonts/bootstrap-icons.woff2?1bb88866b4085542c8ed5fb61b9393dd relative
        to /vendor/bootstrap-icons.css). Without the ?<hash> the preload fetches
        a different URL than the @font-face, so the browser downloads the font
        twice and the preload never satisfies the face — the exact flash this is
        meant to remove. Keep the two in sync if the vendored font is bumped.
    --}}
    <link rel="preload" href="/vendor/fonts/bootstrap-icons.woff2?1bb88866b4085542c8ed5fb61b9393dd" as="font" type="font/woff2" crossorigin>

    <link href="/vendor/bootstrap.min.css" rel="stylesheet">
    <link href="/vendor/bootstrap-icons.css" rel="stylesheet">
    <link href="/vendor/gfonts.css" rel="stylesheet">
    @include('partials.typography-stability')

    <style>
        :root {
            --pc-maroon: #8B1A1A;
            --pc-red: #C0392B;
            --pc-peach: #F4845F;
            --pc-peach-soft: #E88A6E;
            --pc-blush: #FDE8DE;
            --pc-cream: #FFFDF9;
            --pc-cocoa: #5A2920;
            --pc-mute: #8A6A61;
            --pc-radius: 16px;
            --pc-shadow: 0 6px 20px rgba(90, 41, 32, 0.08);
            --pc-sidebar-w: 232px;
        }

        * {
            font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        /*
           Navigation white-flash fix, 2026-09-06.

           Every admin click is a real full-page navigation. Between unloading
           the old document and painting the new one the browser shows the
           <html> canvas colour, which defaults to white — a hard flash against
           this peach theme. Painting <html> with the gradient's own top-left
           stop removes the flash with zero visible change: the body gradient
           still covers every pixel once styled, so this colour is only ever
           seen for the instant between documents.
        */
        html {
            background-color: #F8D7B0;
        }

        /*
           The same guard on <body>. If the body gradient has not painted yet
           (the instant between documents on a full-page navigation), the body
           box would otherwise be transparent and show the white UA default
           through it. This is the gradient's own top-left stop, so once the
           gradient paints it is covered pixel-for-pixel — no visible change.
        */

        h1, h2, h3, h4, h5, h6,
        .page-title, .sidebar-logo h3 {
            font-family: 'Fraunces', Georgia, serif;
            letter-spacing: -0.01em;
        }

        body {
            min-height: 100vh;
            display: flex;

            /*
               Column, not the default row.

               .pc-topbar is display:none on desktop, so the row direction was
               invisible there — but at <=820px the topbar turns into
               display:flex and became a flex ITEM in the body row, rendering
               as a narrow column down the left side and pushing .main-content
               sideways. Stacking the children vertically puts the mobile
               topbar above the content where it belongs.

               Desktop is unaffected: .sidebar is position:fixed (out of flow)
               and .main-content positions itself with margin-left.
            */
            flex-direction: column;

            background-color: #F8D7B0;
            background-image: linear-gradient(
                135deg,
                #F8D7B0 0%,
                #F6B49B 50%,
                #EF8585 100%
            );
        }

        .main-content {
            margin-left: 200px;
            flex: 1;
            min-height: 100vh;
            background: transparent;
            padding: 1.5rem;
        }
        /* ── Sidebar: floating ivory panel ── */
        .sidebar {
            width: var(--pc-sidebar-w);
            min-width: var(--pc-sidebar-w);
            max-width: var(--pc-sidebar-w);
            flex-shrink: 0;
            background: #fff;
            border: 1px solid rgba(138, 106, 97, 0.16);
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0.85rem;
            left: 0.85rem;
            height: calc(100% - 1.7rem);
            z-index: 1040;
            box-shadow: 0 10px 34px rgba(90, 41, 32, 0.10);
            overflow: hidden;
            transition: transform 0.25s ease;
        }

        .sidebar-logo {
            padding: 1.15rem 1rem 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }

        .sidebar-logo .logo-box {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--pc-peach) 0%, var(--pc-red) 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 6px 14px rgba(192, 57, 43, 0.24);
        }

        .sidebar-logo .logo-emoji { font-size: 1.4rem; line-height: 1; }

        .sidebar-logo h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--pc-maroon);
            margin: 0;
        }

        .sidebar-logo p {
            font-size: 0.6rem;
            color: var(--pc-mute);
            letter-spacing: 0.09em;
            text-transform: uppercase;
            margin: 0;
        }

        /* ── User Info Box ── */
        .user-info-box {
            margin: 0 0.7rem 0.35rem;
            padding: 0.65rem 0.8rem;
            background: var(--pc-blush);
            border-radius: 14px;
        }

        .user-info-box .user-name {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--pc-cocoa);
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-info-box .role-badge {
            display: inline-block;
            background-color: rgba(139, 26, 26, 0.1);
            color: var(--pc-maroon);
            padding: 0.12rem 0.55rem;
            border-radius: 999px;
            font-size: 0.58rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-top: 0.3rem;
        }

        .user-info-box .role-badge.admin {
            background-color: var(--pc-peach);
            color: #fff;
        }

        .sidebar-nav {
            padding: 0.5rem 0.7rem 0.75rem;
            flex: 1;
            overflow-y: auto;
        }

        .sidebar-nav::-webkit-scrollbar { width: 5px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(138, 106, 97, 0.25); border-radius: 999px; }

        .nav-group-label {
            font-size: 0.58rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--pc-mute);
            padding: 0.9rem 0.8rem 0.35rem;
            margin: 0;
        }

        .nav-link-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.58rem 0.8rem;
            margin-bottom: 0.12rem;
            border-radius: 12px;
            color: var(--pc-mute);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            transition: background-color 0.18s, color 0.18s;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .nav-link-item:hover {
            background-color: var(--pc-blush);
            color: var(--pc-maroon);
        }

        .nav-link-item.active {
            background: linear-gradient(135deg, var(--pc-maroon) 0%, var(--pc-red) 100%);
            color: #fff;
            box-shadow: 0 6px 16px rgba(139, 26, 26, 0.24);
        }

        .nav-link-item.active i { color: var(--pc-blush); }

        /*
           Reserve the icon's box explicitly so the row's geometry is fixed
           before the icon font finishes loading. font-display:block leaves the
           glyph blank for a moment on navigation; with a locked width AND
           height the blank space is exactly the size the glyph will be, so
           nothing reflows when it appears. display:inline-block keeps the box
           even with no glyph painted.
        */
        .nav-link-item i {
            font-size: 1rem;
            display: inline-block;
            width: 18px;
            height: 1rem;
            line-height: 1;
            text-align: center;
            flex-shrink: 0;
        }


        /* ── Logout Button ── */
        .logout-section {
            padding: 0.6rem 0.7rem 0.8rem;
            border-top: 1px solid rgba(138, 106, 97, 0.14);
        }

        .logout-btn { color: var(--pc-red) !important; }

        .logout-btn:hover {
            background-color: rgba(192, 57, 43, 0.1) !important;
            color: var(--pc-maroon) !important;
        }

        /* ── Topbar (mobile) ── */
        .pc-topbar {
            display: none;
            position: sticky;
            top: 0;
            z-index: 1030;
            align-items: center;
            gap: 0.7rem;
            padding: 0.65rem 0.9rem;
            background: rgba(255, 253, 249, 0.9);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(138, 106, 97, 0.16);
            color: var(--pc-maroon);
        }

        .pc-topbar h3 {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
            color: var(--pc-maroon);
        }

        .pc-burger {
            background: linear-gradient(135deg, var(--pc-peach) 0%, var(--pc-red) 100%);
            border: none;
            color: #fff;
            border-radius: 12px;
            width: 38px;
            height: 38px;
            font-size: 1.15rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 6px 14px rgba(192, 57, 43, 0.22);
        }

        .pc-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(90, 41, 32, 0.4);
            backdrop-filter: blur(2px);
            z-index: 1035;
        }

        /* ── Main Content ── */
        .main-content {
            margin-left: calc(var(--pc-sidebar-w) + 1.7rem);
            min-height: 100vh;
            background: transparent;
            padding: 1.5rem;
        }


        /* ── Page Title ── */
        .page-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--pc-maroon);
            margin-bottom: 1.1rem;
            letter-spacing: -0.01em;
        }

        /* ── Cards ── */
        .content-card {
            background-color: #fff;
            border: 1px solid rgba(138, 106, 97, 0.14);
            border-radius: var(--pc-radius);
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: var(--pc-shadow);
        }

        /* ── Top line: branch bar + notification bell, laid out together ── */
        .pc-topline {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 1.1rem;
        }

        /* The branch bar takes the room; the bell keeps its natural size. */
        .pc-topline > .pc-branchbar {
            flex: 1 1 auto;
            min-width: 0;
            margin-bottom: 0;
        }

        /* With no branch bar on the page, the bell still sits top-right. */
        .pc-topline > .pc-notif {
            margin-left: auto;
        }

        /* ── Branch bar ── */
        .pc-branchbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1.1rem;
            background: var(--pc-blush);
            border: 1px solid rgba(192, 57, 43, 0.16);
            padding: 0.7rem 1rem;
            border-radius: var(--pc-radius);
        }

        .pc-branchbar .label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--pc-mute);
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .pc-branchbar .value {
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 700;
            color: var(--pc-maroon);
        }

        .pc-select {
            border: 1px solid rgba(138, 106, 97, 0.3);
            background: #fff;
            color: var(--pc-cocoa);
            border-radius: 10px;
            padding: 0.4rem 0.7rem;
            font-size: 0.8rem;
            font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-weight: 600;
            outline: none;
            cursor: pointer;
        }

        .pc-select:focus { border-color: var(--pc-peach); }

        /* ── Tables ── */
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        .table-custom thead tr {
            background-color: var(--pc-maroon);
            color: #fff;
        }

        .table-custom thead th {
            padding: 0.65rem 0.8rem;
            font-weight: 700;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table-custom tbody tr { border-bottom: 1px solid rgba(138, 106, 97, 0.14); }
        .table-custom tbody tr:hover { background-color: var(--pc-blush); }

        .table-custom tbody td {
            padding: 0.65rem 0.8rem;
            color: var(--pc-cocoa);
            vertical-align: middle;
        }

        /* ── Buttons ── */
        .btn-primary-custom,
        .btn-danger-custom,
        .btn-edit-custom {
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #fff;
            transition: filter 0.18s, transform 0.12s;
        }

        .btn-primary-custom:hover,
        .btn-danger-custom:hover,
        .btn-edit-custom:hover { filter: brightness(1.07); transform: translateY(-1px); }

        .btn-primary-custom {
            background-color: var(--pc-peach);
            padding: 0.5rem 1.05rem;
            font-size: 0.8rem;
        }

        .btn-danger-custom {
            background-color: var(--pc-red);
            padding: 0.45rem 0.8rem;
            font-size: 0.78rem;
        }

        .btn-edit-custom {
            background-color: var(--pc-peach-soft);
            padding: 0.45rem 0.8rem;
            font-size: 0.78rem;
        }

        /* ── Forms ── */
        .form-label-custom {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--pc-cocoa);
            margin-bottom: 0.35rem;
            display: block;
        }

        .form-control-custom {
            width: 100%;
            border: 1px solid rgba(138, 106, 97, 0.28);
            border-radius: 10px;
            padding: 0.6rem 0.9rem;
            font-size: 0.85rem;
            background-color: var(--pc-cream);
            color: var(--pc-cocoa);
            outline: none;
            font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin-bottom: 0.85rem;
        }

        .form-control-custom:focus {
            border-color: var(--pc-peach);
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(244, 132, 95, 0.18);
        }

        /* ── Search Bar ── */
        .search-wrapper { position: relative; }

        .search-wrapper i {
            position: absolute;
            left: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--pc-mute);
            font-size: 0.85rem;
        }

        .search-input {
            border: 1px solid rgba(138, 106, 97, 0.28);
            border-radius: 10px;
            padding: 0.55rem 0.9rem 0.55rem 2.3rem;
            font-size: 0.85rem;
            background-color: #fff;
            color: var(--pc-cocoa);
            outline: none;
            font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            width: 250px;
            max-width: 100%;
        }

        .search-input:focus { border-color: var(--pc-peach); }

        /* ── Success/Error Alerts ── */
        .alert-success-custom {
                position: fixed;
                bottom: 24px;
                right: 24px;
                z-index: 9999;

                background: #8B1A1A;
                color: #FFFDF9;

                padding: 0.9rem 1.2rem;
                border-radius: 12px;

                font-size: 0.87rem;
                font-weight: 600;

                border-left: 5px solid #F4845F;

                box-shadow: 0 10px 28px rgba(90, 41, 32, 0.25);

                max-width: min(500px, calc(100vw - 40px));
                margin: 0;

                transition: opacity .4s ease, transform .4s ease;
            }

        /* Permission / error toast — same shape, warning colours. */
        .alert-error-custom {
                position: fixed;
                bottom: 24px;
                right: 24px;
                z-index: 9999;

                background: #C0392B;
                color: #FFFDF9;

                padding: 0.9rem 1.2rem;
                border-radius: 12px;

                font-size: 0.87rem;
                font-weight: 600;

                border-left: 5px solid #F8D7B0;

                box-shadow: 0 10px 28px rgba(90, 41, 32, 0.25);

                max-width: min(500px, calc(100vw - 40px));
                margin: 0;

                transition: opacity .4s ease, transform .4s ease;
            }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            :root { --pc-sidebar-w: 200px; }
            .main-content { padding: 1.15rem; }
        }
        @media (max-width: 820px) {
            .alert-success-custom,
            .alert-error-custom {
                bottom: 15px;
                left: 15px;
                right: 15px;
                max-width: none;
            }
        }
        @media (max-width: 820px) {
            .sidebar {
                top: 0;
                left: 0;
                height: 100%;
                border-radius: 0 20px 20px 0;
                transform: translateX(-105%);
                box-shadow: 0 0 40px rgba(90, 41, 32, 0.3);
            }
            body.pc-nav-open .sidebar { transform: translateX(0); }
            body.pc-nav-open .pc-overlay { display: block; }
            .pc-topbar { display: flex; }
            .main-content { margin-left: 0; padding: 1rem; }
            .search-input { width: 100%; }
        }


        @media (max-width: 540px) {
            .main-content { padding: 0.85rem; }
            .content-card { padding: 1rem; }
            .pc-branchbar { flex-direction: column; align-items: stretch; }
            .pc-branchbar form { width: 100%; }
            .pc-select { width: 100%; }
            .page-title { font-size: 1.15rem; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }
    </style>

    @stack('styles')
</head>

<body>

    @php
        // Get the currently logged-in ADMIN/STAFF user
        $adminUser = Auth::guard('admin')->user();
    @endphp

    {{-- Mobile Topbar --}}
    <header class="pc-topbar">
        <button type="button" class="pc-burger" onclick="document.body.classList.toggle('pc-nav-open')" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>
        <h3>🍑 Peachy Admin</h3>
    </header>

    <div class="pc-overlay" onclick="document.body.classList.remove('pc-nav-open')"></div>

    {{-- Sidebar --}}
    <div class="sidebar">

        <div class="sidebar-logo">
            <div class="logo-box">
                <span class="logo-emoji">🍑</span>
            </div>
            <div>
                <h3>Peachy</h3>
                <p>Cakes &amp; Deli Cafe</p>
            </div>
        </div>

        {{-- User Info Box --}}
        @if($adminUser)
            <div class="user-info-box">
                <p class="user-name">
                    {{ $adminUser->name }}
                </p>

                <span class="role-badge {{ $adminUser->role === 'admin' ? 'admin' : '' }}">
                    {{ ucfirst($adminUser->role) }}
                </span>
            </div>
        @endif

        <nav class="sidebar-nav">

            <p class="nav-group-label">Operations</p>

            {{-- Home --}}

            <a href="{{ route('admin.home') }}"
                class="nav-link-item {{ request()->routeIs('admin.home') ? 'active' : '' }}">
                <i class="bi bi-house"></i>
                <span>Home</span>
            </a>

            {{-- Completed Orders --}}
            <a href="{{ route('admin.completed-orders') }}"
                class="nav-link-item {{ request()->routeIs('admin.completed-orders') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i>
                <span>Completed Orders</span>
            </a>

            {{-- Inventory --}}
            <a href="{{ route('admin.inventory') }}"
                class="nav-link-item {{ request()->routeIs('admin.inventory') ? 'active' : '' }}">
                <i class="bi bi-box-seam"></i>
                <span>Inventory</span>
            </a>

            {{-- Menu Items --}}
            <a href="{{ route('admin.menu-items') }}"
                class="nav-link-item {{ request()->routeIs('admin.menu-items') ? 'active' : '' }}">
                <i class="bi bi-grid"></i>
                <span>Menu Items</span>
            </a>

            {{-- QR & Table Codes.

                 This lives in the shared Operations section, NOT the admin-only
                 block below. Issuing a fallback code to a customer whose camera
                 will not scan is a counter action a staff member performs during
                 a shift, and routes/web.php has always scoped all three
                 qr-generator routes to role:admin,staff. The link was simply
                 never moved out of the admin-only block, so staff were
                 authorised for the page but had no way to navigate to it. --}}
            <a href="{{ route('admin.qr-generator') }}"
                class="nav-link-item {{ request()->routeIs('admin.qr-generator') ? 'active' : '' }}">
                <i class="bi bi-qr-code"></i>
                <span>QR &amp; Table Codes</span>
            </a>

            {{-- Vouchers.

                 Shared: staff get a read-only view of the All Vouchers table
                 so they can read active codes to customers. The Spin Wheel
                 toggle, the Create form and the Actions column are hidden for
                 non-admins by admin.vouchers itself, and every mutating route
                 is behind role:admin. --}}
            <a href="{{ route('admin.vouchers') }}"
                class="nav-link-item {{ request()->routeIs('admin.vouchers') ? 'active' : '' }}">
                <i class="bi bi-ticket-perforated"></i>
                <span>Vouchers</span>
            </a>

            {{-- ══════════ ADMIN ONLY SECTION ══════════ --}}
            @if($adminUser && $adminUser->role === 'admin')

                {{-- Summary --}}
                <a href="{{ route('admin.summary') }}"
                    class="nav-link-item {{ request()->routeIs('admin.summary') ? 'active' : '' }}">
                    <i class="bi bi-bar-chart"></i>
                    <span>Summary</span>
                </a>

                {{-- Analytics --}}
                <a href="{{ route('admin.analytics') }}"
                    class="nav-link-item {{ request()->routeIs('admin.analytics') ? 'active' : '' }}">
                    <i class="bi bi-graph-up"></i>
                    <span>Analytics</span>
                </a>

                {{-- Add Category --}}
                <a href="{{ route('admin.add-category') }}"
                    class="nav-link-item {{ request()->routeIs('admin.add-category') ? 'active' : '' }}">
                    <i class="bi bi-plus-square"></i>
                    <span>Add Category</span>
                </a>

                {{-- Menu Options --}}
                <a href="{{ route('admin.menu-options') }}"
                    class="nav-link-item {{ request()->routeIs('admin.menu-options') ? 'active' : '' }}">
                    <i class="bi bi-menu-button"></i>
                    <span>Menu Options</span>
                </a>

                {{-- Branches --}}
                <a href="{{ route('admin.branches') }}"
                    class="nav-link-item {{ request()->routeIs('admin.branches') ? 'active' : '' }}">
                    <i class="bi bi-building"></i>
                    <span>Branches</span>
                </a>

                {{-- Ads --}}
                <a href="{{ route('admin.ads') }}"
                    class="nav-link-item {{ request()->routeIs('admin.ads') ? 'active' : '' }}">
                    <i class="bi bi-megaphone"></i>
                    <span>Ads</span>
                </a>

                {{-- Staff Accounts --}}
                <a href="{{ route('admin.users') }}"
                    class="nav-link-item {{ request()->routeIs('admin.users') ? 'active' : '' }}">
                    <i class="bi bi-people"></i>
                    <span>Staff Accounts</span>
                </a>

            @endif

            {{-- Account — ADMIN ONLY as of 2026-09-01.

                 This used to sit outside the admin-only block so a staff member
                 could reach it to change their own password. Staff no longer
                 have any self-service password path: only the admin sets a
                 staff password, from the Staff Accounts page.

                 Hiding the link is a convenience, not the control. The route
                 itself is behind `role:admin`, so typing the URL or crafting a
                 PUT is refused server-side. --}}
            @if($adminUser && $adminUser->role === 'admin')
                <a href="{{ route('admin.account') }}"
                    class="nav-link-item {{ request()->routeIs('admin.account') ? 'active' : '' }}">
                    <i class="bi bi-gear"></i>
                    <span>Account</span>
                </a>
            @endif

        </nav>

        {{-- Logout Section --}}
        @if($adminUser)
            <div class="logout-section">
                <form action="{{ route('admin.logout') }}" method="POST" style="margin: 0;">
                    @csrf

                    <button type="submit" class="nav-link-item logout-btn">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Logout</span>
                    </button>
                </form>
            </div>
        @endif

    </div>

    {{-- Main Content --}}
    <div class="main-content">

        {{-- Branch Selector --}}
        @php

            $showBranchDropdown = request()->routeIs(
                'admin.home',
                'admin.completed-orders',
                'admin.inventory',
                'admin.menu-items',
                'admin.summary',
                'admin.qr-generator',
                'admin.ads'
            );

            // This is the internal admin picker, not a customer-facing one —
            // it must include closed branches too, so an admin can still
            // switch their own view to a closed branch to manage its menu
            // items/inventory or reactivate it. Closed ones are labelled.
            $allBranches = $showBranchDropdown
                ? \App\Models\Branch::orderBy('id')
                    ->get()
                : collect();

            $selectedBranchId = session('selected_branch_id', 'all');

        @endphp

        {{--
            Top line of the content area: the branch bar (on the pages that
            have one) and the notification bell, side by side.

            The bell used to be position:fixed in the top-right corner, which
            floated it over this bar and its branch dropdown. Laying both out
            in one flex row means they can never overlap, and the bell keeps a
            consistent place on pages with and without the branch bar.
        --}}
        <div class="pc-topline">

        @if($showBranchDropdown)

            <div class="pc-branchbar">

                <span class="label">
                    <i class="bi bi-building"></i>
                    Viewing:

                    <span class="value">
                        @php
                            $viewingBranch = $selectedBranchId === 'all' ? null : $allBranches->find($selectedBranchId);
                        @endphp
                        {{ $viewingBranch
                            ? $viewingBranch->name . (!$viewingBranch->is_active ? ' (Closed)' : '')
                            : 'All Branches' }}
                    </span>
                </span>

                @if($adminUser && $adminUser->role === 'admin')

                    {{-- Admin only — may branch filter --}}
                    <form action="{{ route('admin.branches.select') }}"
                        method="POST"
                        style="display:flex;gap:0.5rem;align-items:center;margin:0;">

                        @csrf

                        <select name="branch_id" onchange="this.form.submit()" class="pc-select">

                            <option value="all"
                                {{ $selectedBranchId === 'all' ? 'selected' : '' }}>
                                All Branches
                            </option>

                            @foreach($allBranches as $b)

                                <option value="{{ $b->id }}"
                                    {{ $selectedBranchId == $b->id ? 'selected' : '' }}>
                                    {{ $b->name }}{{ !$b->is_active ? ' (Closed)' : '' }}
                                </option>

                            @endforeach

                        </select>

                    </form>

                @else

                    {{-- Staff — locked sa sariling branch --}}
                    @if($adminUser)
                        <span class="label">
                            <i class="bi bi-building"></i>
                            {{ $adminUser->branch?->name ?? 'Main Branch' }}
                        </span>
                    @endif

                @endif

            </div>

        @endif

            {{-- Notifications --}}
            @include('admin.partials.notification-bell')

        </div>

        {{-- Success Message --}}
        @if(session('success'))

            <div class="alert-success-custom" data-auto-dismiss>
                <i class="bi bi-check-circle"></i>
                {{ session('success') }}
            </div>

        @endif

        {{-- Error / permission Message --}}
        @if(session('error'))

            <div class="alert-error-custom" data-auto-dismiss>
                <i class="bi bi-shield-exclamation"></i>
                {{ session('error') }}
            </div>

        @endif

        @yield('content')

    </div>

    <script src="/vendor/bootstrap.bundle.min.js"></script>

    <script>
        document.querySelectorAll('[data-auto-dismiss]').forEach(function (toast) {
            setTimeout(function () {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(6px)';
                setTimeout(function () { toast.remove(); }, 400);
            }, 2500);
        });
    </script>

    @stack('scripts')

    {{-- Show/hide password toggle, shared by every password field on admin pages (account, staff accounts). --}}
    @include('admin.partials.password-toggle')
</body>

</html>

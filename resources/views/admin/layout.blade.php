<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Peachy Admin')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #f5f5f5;
            min-height: 100vh;
            display: flex;
        }

        /* ── Sidebar ── */
        .sidebar {
            width: 200px;
            min-height: 100vh;
            background-color: #C0392B;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            z-index: 100;
        }

        .sidebar-logo {
            padding: 1.25rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        }

        .sidebar-logo .logo-box {
            width: 70px;
            height: 70px;
            background-color: white;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.4rem;
        }

        .sidebar-logo .logo-emoji {
            font-size: 1.8rem;
        }

        .sidebar-logo h3 {
            font-size: 0.9rem;
            font-weight: 700;
            color: #C0392B;
            margin: 0;
        }

        .sidebar-logo p {
            font-size: 0.5rem;
            color: #aaa;
            margin: 0;
        }

        /* ── User Info Box ── */
        .user-info-box {
            padding: 0.65rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            text-align: center;
        }

        .user-info-box .user-name {
            font-size: 0.78rem;
            font-weight: 600;
            color: white;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-info-box .user-role {
            font-size: 0.6rem;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .user-info-box .role-badge {
            display: inline-block;
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
            font-size: 0.55rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 0.2rem;
        }

        .user-info-box .role-badge.admin {
            background-color: #F4845F;
        }

        .sidebar-nav {
            padding: 0.75rem 0;
            flex: 1;
            overflow-y: auto;
        }

        .nav-link-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.6rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 500;
            transition: background-color 0.2s;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            font-family: 'Poppins', sans-serif;
        }

        .nav-link-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-link-item.active {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .nav-link-item i {
            font-size: 1rem;
            width: 18px;
            text-align: center;
        }

        /* ── Logout Button ── */
        .logout-section {
            padding: 0.75rem 0;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
        }

        .logout-btn {
            color: #ffcccc !important;
        }

        .logout-btn:hover {
            background-color: rgba(0, 0, 0, 0.2) !important;
            color: white !important;
        }

        /* ── Main Content ── */
        .main-content {
            margin-left: 200px;
            flex: 1;
            min-height: 100vh;
            background-color: #fde8de;
            padding: 1.5rem;
        }

        /* ── Page Title ── */
        .page-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 1.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ── Cards ── */
        .content-card {
            background-color: white;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        /* ── Tables ── */
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.78rem;
        }

        .table-custom thead tr {
            background-color: #C0392B;
            color: white;
        }

        .table-custom thead th {
            padding: 0.6rem 0.75rem;
            font-weight: 600;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .table-custom tbody tr {
            border-bottom: 1px solid #f0f0f0;
        }

        .table-custom tbody tr:hover {
            background-color: #fef5f2;
        }

        .table-custom tbody td {
            padding: 0.6rem 0.75rem;
            color: #444;
            vertical-align: middle;
        }

        /* ── Buttons ── */
        .btn-primary-custom {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.45rem 1rem;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: filter 0.2s;
        }

        .btn-primary-custom:hover {
            filter: brightness(0.92);
        }

        .btn-danger-custom {
            background-color: #C0392B;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
        }

        .btn-edit-custom {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
        }

        /* ── Forms ── */
        .form-label-custom {
            font-size: 0.78rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 0.3rem;
            display: block;
        }

        .form-control-custom {
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0.55rem 0.85rem;
            font-size: 0.82rem;
            background-color: #f9f9f9;
            color: #333;
            outline: none;
            font-family: 'Poppins', sans-serif;
            margin-bottom: 0.85rem;
        }

        .form-control-custom:focus {
            border-color: #F4845F;
            background-color: #fff;
        }

        /* ── Search Bar ── */
        .search-wrapper {
            position: relative;
        }

        .search-wrapper i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            font-size: 0.85rem;
        }

        .search-input {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0.5rem 0.85rem 0.5rem 2.2rem;
            font-size: 0.82rem;
            background-color: white;
            color: #333;
            outline: none;
            font-family: 'Poppins', sans-serif;
            width: 250px;
        }

        .search-input:focus {
            border-color: #F4845F;
        }

        /* ── Success/Error Alerts ── */
        .alert-success-custom {
            background-color: #d4edda;
            color: #155724;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            border-left: 4px solid #28a745;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 60px;
            }

            .sidebar .nav-link-item span {
                display: none;
            }

            .sidebar-logo h3,
            .sidebar-logo p {
                display: none;
            }

            .user-info-box {
                display: none;
            }

            .main-content {
                margin-left: 60px;
            }
        }
    </style>
    @stack('styles')
</head>

<body>

    {{-- Sidebar --}}
    <div class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-box">
                <span class="logo-emoji">🍑</span>
                <h3>Peachy</h3>
                <p>Cakes and Deli Cafe</p>
            </div>
        </div>

        {{-- User Info Box --}}
        @auth
        <div class="user-info-box">
            <p class="user-name">{{ auth()->user()->name }}</p>
            <span class="role-badge {{ auth()->user()->role === 'admin' ? 'admin' : '' }}">
                {{ ucfirst(auth()->user()->role) }}
            </span>
        </div>
        @endauth

        <nav class="sidebar-nav">
            <a href="{{ route('admin.home') }}" class="nav-link-item {{ request()->routeIs('admin.home') ? 'active' : '' }}">
                <i class="bi bi-house"></i><span>Home</span>
            </a>

            <a href="{{ route('admin.completed-orders') }}" class="nav-link-item {{ request()->routeIs('admin.completed-orders') ? 'active' : '' }}">
                <i class="bi bi-receipt"></i><span>Completed Orders</span>
            </a>

            <a href="{{ route('admin.inventory') }}" class="nav-link-item {{ request()->routeIs('admin.inventory') ? 'active' : '' }}">
                <i class="bi bi-box-seam"></i><span>Inventory</span>
            </a>

            <a href="{{ route('admin.menu-items') }}" class="nav-link-item {{ request()->routeIs('admin.menu-items') ? 'active' : '' }}">
                <i class="bi bi-grid"></i><span>Menu Items</span>
            </a>

            {{-- Admin Only Section --}}
            @if(auth()->check() && auth()->user()->role === 'admin')
            <a href="{{ route('admin.summary') }}" class="nav-link-item {{ request()->routeIs('admin.summary') ? 'active' : '' }}">
                <i class="bi bi-bar-chart"></i><span>Summary</span>
            </a>

            <a href="{{ route('admin.add-category') }}" class="nav-link-item {{ request()->routeIs('admin.add-category') ? 'active' : '' }}">
                <i class="bi bi-plus-square"></i><span>Add Category</span>
            </a>


            <a href="{{ route('admin.menu-options') }}" class="nav-link-item {{ request()->routeIs('admin.menu-options') ? 'active' : '' }}">
                <i class="bi bi-menu-button"></i><span>Menu Options</span>
            </a>

            <a href="{{ route('admin.qr-generator') }}" class="nav-link-item {{ request()->routeIs('admin.qr-generator') ? 'active' : '' }}">
                <i class="bi bi-qr-code"></i><span>QR Generator</span>
            </a>

            <a href="{{ route('admin.vouchers') }}" class="nav-link-item {{ request()->routeIs('admin.vouchers') ? 'active' : '' }}">
                <i class="bi bi-ticket-perforated"></i><span>Vouchers</span>
            </a>

            <a href="{{ route('admin.branches') }}" class="nav-link-item {{ request()->routeIs('admin.branches') ? 'active' : '' }}">
                <i class="bi bi-building"></i><span>Branches</span>
            </a>

            <a href="{{ route('admin.ads') }}" class="nav-link-item {{ request()->routeIs('admin.ads') ? 'active' : '' }}">
                <i class="bi bi-megaphone"></i><span>Ads</span>
            </a>

            <a href="{{ route('admin.account') }}" class="nav-link-item {{ request()->routeIs('admin.account') ? 'active' : '' }}">
                <i class="bi bi-gear"></i><span>Account</span>
            </a>
            @endif
        </nav>

        {{-- Logout Section --}}
        @auth
        <div class="logout-section">
            <form action="{{ route('admin.logout') }}" method="POST" style="margin: 0;">
                @csrf
                <button type="submit" class="nav-link-item logout-btn">
                    <i class="bi bi-box-arrow-right"></i><span>Logout</span>
                </button>
            </form>
        </div>
        @endauth
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
        'admin.new-menu-item',
        'admin.menu-items.edit',
        'admin.summary',
        'admin.qr-generator',
        'admin.ads'
        );

        $allBranches = $showBranchDropdown
        ? \App\Models\Branch::where('is_active', true)->orderBy('id')->get()
        : collect();

        $selectedBranchId = session('selected_branch_id', 'all');
        @endphp

        @if($showBranchDropdown)
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;background:white;padding:0.6rem 1rem;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
            <span style="font-size:0.78rem;font-weight:600;color:#555;">
                <i class="bi bi-building"></i> Viewing:
                <span style="color:#F4845F;">
                    {{ $selectedBranchId === 'all' ? 'All Branches' : ($allBranches->find($selectedBranchId)?->name ?? 'All Branches') }}
                </span>
            </span>

            @if(auth()->check() && auth()->user()->role === 'admin')
            {{-- Admin only — may branch filter --}}
            <form action="{{ route('admin.branches.select') }}" method="POST" style="display:flex;gap:0.5rem;align-items:center;margin:0;">
                @csrf
                <select name="branch_id" onchange="this.form.submit()"
                    style="border:1px solid #ddd;border-radius:8px;padding:0.3rem 0.6rem;font-size:0.75rem;font-family:'Poppins',sans-serif;outline:none;">
                    <option value="all" {{ $selectedBranchId === 'all' ? 'selected' : '' }}>All Branches</option>

                    @foreach($allBranches as $b)
                    <option value="{{ $b->id }}" {{ $selectedBranchId == $b->id ? 'selected' : '' }}>
                        {{ $b->name }}
                    </option>
                    @endforeach
                </select>
            </form>
            @else
            {{-- Staff — locked sa sariling branch --}}
            <span style="font-size:0.75rem;color:#888;font-weight:600;">
                <i class="bi bi-building"></i>
                {{ auth()->user()->branch?->name ?? 'Main Branch' }}
            </span>
            @endif
        </div>
        @endif

        {{-- Success Message --}}
        @if(session('success'))
        <div class="alert-success-custom">
            <i class="bi bi-check-circle"></i> {{ session('success') }}
        </div>
        @endif

        @yield('content')
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>

</html>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main Menu - Peachy</title>

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
            background-color: #e8e8e8;
            min-height: 100vh;
            display: flex;
            justify-content: center;
        }

        .app-wrapper {
            width: 100%;
            max-width: 420px;
            min-height: 100vh;
            background-color: #fff;
            display: flex;
            flex-direction: column;
            padding-bottom: 70px;
        }

        .top-header {
            background-color: #fff;
            padding: 1rem 1rem 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-circle {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background-color: rgba(244, 132, 95, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            flex-shrink: 0;
        }

        .business-info h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #F4845F;
            margin: 0;
        }

        .business-info p {
            font-size: 0.7rem;
            color: #aaa;
            margin: 0;
        }

        .search-bar {
            padding: 0.4rem 1rem 0.6rem;
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .input-wrapper {
            flex: 1;
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            padding: 0.45rem 0.85rem 0.45rem 2.2rem;
            font-size: 0.82rem;
            background-color: #f5f5f5;
            outline: none;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #bbb;
            font-size: 0.85rem;
        }

        .btn-cancel-search {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 20px;
            padding: 0.42rem 1rem;
            font-size: 0.78rem;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 0.5rem 1rem;
            margin: 0 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            text-align: center;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 0.5rem 1rem;
            margin: 0 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
        }

        .back-bar {
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-bar a {
            color: #F4845F;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
        }

        /* ── Category Section ── */
        .category-section {
            background-color: #F4845F;
            padding: 0.6rem 0.75rem;
            flex: 1;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: white;
            margin-bottom: 0.75rem;
            text-align: center;
        }

        .category-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem;
        }

        .category-card {
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            aspect-ratio: 1 / 0.85;
            background-color: rgba(255, 255, 255, 0.25);
            cursor: pointer;
        }

        .category-card:hover {
            transform: scale(1.02);
        }

        .category-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .category-card .empty-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.5);
            font-size: 2.5rem;
        }

        .category-card .label {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.6));
            color: white;
            font-size: 0.78rem;
            font-weight: 500;
            padding: 1rem 0.6rem 0.45rem;
            text-align: center;
        }

        /* ── Item Cards ── */
        .item-card {
            background: white;
            border-radius: 12px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .item-image {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .item-info {
            flex: 1;
            min-width: 0;
        }

        .item-info h4 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
            margin: 0 0 0.2rem;
        }

        .item-info .desc {
            font-size: 0.7rem;
            color: #888;
            margin: 0 0 0.3rem;
            line-height: 1.3;
            max-height: 2.6em;
            overflow: hidden;
        }

        .item-info .price {
            font-size: 0.95rem;
            font-weight: 700;
            color: #F4845F;
            margin: 0;
        }

        .add-cart-btn {
            background-color: #C0392B;
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            font-family: 'Poppins', sans-serif;
        }

        .add-cart-btn:hover {
            filter: brightness(0.9);
        }

        /* ── Bottom Nav ── */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            width: 100%;
            max-width: 420px;
            background-color: #fff;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 0.5rem 0 0.4rem;
            z-index: 100;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            text-decoration: none;
            color: #F4845F;
            font-size: 0.65rem;
            font-weight: 500;
        }

        .nav-item i {
            font-size: 1.3rem;
        }

        .nav-item.active {
            color: #F4845F;
        }

        @media (max-width: 420px) {
            .app-wrapper {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

    <div class="app-wrapper">

        {{-- Top Header --}}
        <div class="top-header">
            <div class="logo-circle">🍑</div>
            <div class="business-info">
                <h2>Peachy</h2>
                <p>Cakes and Deli Cafe</p>
            </div>
        </div>

        {{-- Search Bar --}}
        <div class="search-bar">
            <div class="input-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input type="text" placeholder="Search..." id="searchInput">
            </div>
            <button class="btn-cancel-search" onclick="document.getElementById('searchInput').value = ''">Cancel</button>
        </div>

        {{-- Success/Error Messages --}}
        @if(session('success'))
        <div class="alert-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
        <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        {{-- Branch Selector — pickup customers only --}}
        @php
        $orderType = session('order_type');
        $selectedBranchId = session('branch_id');
        @endphp

        @if(Auth::check() && $orderType !== 'dine_in')
        <div style="padding:0.6rem 1rem;background:#fff9f6;border-bottom:1px solid #fde8de;">
            <form action="{{ route('customer.select-branch') }}" method="POST" style="display:flex;gap:0.5rem;align-items:center;">
                @csrf
                <i class="bi bi-building" style="color:#F4845F;font-size:1rem;flex-shrink:0;"></i>
                <select name="branch_id" onchange="this.form.submit()"
                    style="flex:1;border:1px solid #fde8de;border-radius:8px;padding:0.4rem 0.65rem;font-size:0.78rem;font-family:'Poppins',sans-serif;outline:none;background:#fff;color:#333;">
                    <option value="">-- Select Pick-up Branch --</option>
                    @foreach(isset($branches) ? $branches : \App\Models\Branch::where('is_active',true)->orderBy('id')->get() as $branch)
                    <option value="{{ $branch->id }}" {{ $selectedBranchId == $branch->id ? 'selected' : '' }}>
                        {{ $branch->name }}{{ $branch->address ? ' — ' . $branch->address : '' }}
                    </option>
                    @endforeach
                </select>
            </form>

            @if(!$selectedBranchId)
            <div style="font-size:0.72rem;color:#C0392B;font-weight:600;margin-top:0.3rem;padding-left:1.5rem;">
                <i class="bi bi-exclamation-circle"></i> Please select a pick-up branch to view the menu.
            </div>
            @else
            <div style="font-size:0.7rem;color:#4CAF50;margin-top:0.25rem;padding-left:1.5rem;">
                <i class="bi bi-check-circle"></i>
                Pick-up at: <strong>{{ \App\Models\Branch::find($selectedBranchId)?->name }}</strong>
                — <a href="#" onclick="clearBranch()" style="color:#F4845F;font-size:0.68rem;">Change</a>
            </div>
            @endif
        </div>
        @endif

        {{-- Dine-in banner --}}
        @if($orderType === 'dine_in' && $selectedBranchId)
        <div style="padding:0.45rem 1rem;background:#e8f5e9;border-bottom:1px solid #c8e6c9;display:flex;align-items:center;gap:0.5rem;">
            <i class="bi bi-shop" style="color:#4CAF50;"></i>
            <span style="font-size:0.75rem;color:#2e7d32;font-weight:600;">
                Dine-in • Table {{ session('table_number') }} •
                {{ \App\Models\Branch::find($selectedBranchId)?->name }}
            </span>
        </div>
        @endif

        @if(isset($items))
        {{-- ITEMS LIST VIEW with subcategory tabs --}}
        <div class="back-bar">
            <a href="{{ route('customer.menu') }}"><i class="bi bi-arrow-left"></i> Back to Categories</a>
        </div>

        <div class="category-section">
            <p class="section-title">{{ $category->name ?? 'Items' }}</p>

            {{-- Subcategory Tabs --}}
            @if(isset($subcategories) && $subcategories->count() > 0)
            <div style="display:flex;gap:0.4rem;overflow-x:auto;padding-bottom:0.6rem;scrollbar-width:none;-ms-overflow-style:none;">
                <button class="subcategory-tab active" onclick="filterSubcategory(this, 'all')" style="white-space:nowrap;background:white;color:#C0392B;border:2px solid white;border-radius:20px;padding:0.35rem 0.85rem;font-size:0.75rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;flex-shrink:0;">
                    All
                </button>
                @foreach($subcategories as $sub)
                <button class="subcategory-tab" onclick="filterSubcategory(this, '{{ $sub->id }}')" style="white-space:nowrap;background:transparent;color:white;border:2px solid rgba(255,255,255,0.5);border-radius:20px;padding:0.35rem 0.85rem;font-size:0.75rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;flex-shrink:0;">
                    {{ $sub->name }}
                </button>
                @endforeach
            </div>
            @endif

            {{-- Items Grid --}}
            @if(count($items) > 0)
            <div class="items-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                @foreach($items as $item)
                <a href="{{ route('customer.item', $item->id) }}" class="item-grid-card" data-subcategory="{{ $item->subcategory_id ?? 'none' }}" style="background:white;border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;display:flex;flex-direction:column;">
                    <div style="width:100%;aspect-ratio:1/0.85;background:#f0f0f0;overflow:hidden;display:flex;align-items:center;justify-content:center;">
                        @if($item->image)
                        <img src="{{ asset($item->image) }}" alt="{{ $item->name }}" style="width:100%;height:100%;object-fit:cover;">
                        @else
                        <i class="bi bi-image" style="color:#ddd;font-size:2rem;"></i>
                        @endif
                    </div>
                    <div style="padding:0.5rem 0.6rem;">
                        <p style="font-size:0.78rem;font-weight:600;color:#333;margin:0 0 0.15rem;line-height:1.3;">{{ $item->name }}</p>
                        <p style="font-size:0.82rem;font-weight:700;color:#F4845F;margin:0;">₱{{ number_format($item->price, 2) }}</p>
                    </div>
                </a>
                @endforeach
            </div>
            @else
            <div style="background:white;border-radius:12px;padding:2rem;text-align:center;color:#888;font-size:0.85rem;">
                No items available in this category
            </div>
            @endif
        </div>
        @else
        {{-- CATEGORIES GRID VIEW --}}
        <div class="category-section">
            <p class="section-title">Menu Categories</p>

            {{-- No branch selected yet — show message --}}
            @if(Auth::check() && $orderType !== 'dine_in' && !$selectedBranchId)
            <div style="background:white;border-radius:12px;padding:2rem 1rem;text-align:center;margin-bottom:0.75rem;">
                <i class="bi bi-building" style="font-size:2.5rem;color:#F4845F;display:block;margin-bottom:0.75rem;"></i>
                <p style="font-size:0.88rem;font-weight:600;color:#333;margin-bottom:0.25rem;">Select a Branch First</p>
                <p style="font-size:0.75rem;color:#888;">Please select your pick-up branch above to see available menu items.</p>
            </div>
            @else
            <div class="category-grid">
                @if(isset($categories) && count($categories) > 0)
                @foreach($categories as $category)
                <div class="category-card" onclick="window.location.href=`{{ route('customer.items', $category->id) }}`">
                    @if($category->image)
                    <img src="{{ asset($category->image) }}" alt="{{ $category->name }}">
                    @else
                    <div class="empty-placeholder"><i class="bi bi-image"></i></div>
                    @endif
                    <div class="label">{{ $category->name }}</div>
                </div>
                @endforeach
                @else
                <div style="grid-column: 1 / -1; text-align:center; color:rgba(255,255,255,0.85); padding:2rem; font-size:0.85rem;">
                    No categories available yet
                </div>
                @endif
            </div>
            @endif {{-- end branch check --}}
        </div>
        @endif

        {{-- Bottom Navigation --}}
        <div class="bottom-nav">
            <a href="{{ route('customer.orders') }}" class="nav-item">
                <i class="bi bi-receipt"></i><span>Orders</span>
            </a>
            <a href="{{ route('customer.menu') }}" class="nav-item active">
                <i class="bi bi-grid"></i><span>Menu</span>
            </a>
            <a href="{{ route('customer.cart') }}" class="nav-item" style="position:relative;">
                <i class="bi bi-cart"></i>
                @php $cartCount = count(session('cart', [])); @endphp
                @if($cartCount > 0)
                <span style="position:absolute;top:-4px;right:-4px;background:#C0392B;color:white;border-radius:50%;width:16px;height:16px;font-size:0.55rem;font-weight:700;display:flex;align-items:center;justify-content:center;">{{ $cartCount }}</span>
                @endif
                <span>Cart</span>
            </a>
            <a href="{{ route('customer.more') }}" class="nav-item">
                <i class="bi bi-three-dots"></i><span>More</span>
            </a>
            <a href="#" class="nav-item">
                <i class="bi bi-question-circle"></i><span>Help</span>
            </a>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function filterSubcategory(btn, subcatId) {
            // Update active tab
            document.querySelectorAll('.subcategory-tab').forEach(t => {
                t.style.background = 'transparent';
                t.style.color = 'white';
                t.style.borderColor = 'rgba(255,255,255,0.5)';
                t.classList.remove('active');
            });
            btn.style.background = 'white';
            btn.style.color = '#C0392B';
            btn.style.borderColor = 'white';
            btn.classList.add('active');

            // Filter items
            document.querySelectorAll('.item-grid-card').forEach(card => {
                if (subcatId === 'all') {
                    card.style.display = 'flex';
                } else {
                    card.style.display = card.dataset.subcategory === subcatId ? 'flex' : 'none';
                }
            });
        }

        function clearBranch() {
            if (confirm('Changing branch will clear your cart. Continue?')) {
                fetch('{{ route("customer.select-branch") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        branch_id: ''
                    })
                }).then(() => window.location.reload());
            }
        }
    </script>
</body>

</html>
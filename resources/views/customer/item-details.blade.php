<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ isset($item) ? $item->name : 'Item' }} - Peachy</title>
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
            background-color: #F4845F;
            display: flex;
            flex-direction: column;
            padding-bottom: 70px;
        }

        /* Header */
        .top-header {
            background-color: #fff;
            padding: 0.75rem 1rem 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-color: rgba(244, 132, 95, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .business-info h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #F4845F;
            margin: 0;
        }

        .business-info p {
            font-size: 0.65rem;
            color: #aaa;
            margin: 0;
        }

        /* Search */
        .search-bar {
            padding: 0.3rem 1rem 0.5rem;
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
            padding: 0.4rem 0.85rem 0.4rem 2rem;
            font-size: 0.78rem;
            background-color: #f5f5f5;
            color: #333;
            outline: none;
        }

        .search-icon {
            position: absolute;
            left: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: #bbb;
            font-size: 0.8rem;
        }

        .btn-cancel-search {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 20px;
            padding: 0.38rem 0.9rem;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
        }

        /* Item Image */
        .item-image {
            width: 100%;
            height: 220px;
            object-fit: cover;
            display: block;
        }

        .item-image-placeholder {
            width: 100%;
            height: 220px;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .item-image-placeholder i {
            font-size: 3rem;
            color: #ddd;
        }

        /* Item Info */
        .item-info {
            padding: 0.85rem 1rem 0.5rem;
            background-color: #fff;
        }

        .item-name-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.2rem;
        }

        .item-name {
            font-size: 1rem;
            font-weight: 700;
            color: #333;
        }

        .item-price {
            font-size: 0.95rem;
            font-weight: 700;
            color: #333;
            white-space: nowrap;
            margin-left: 0.5rem;
        }

        .item-description {
            font-size: 0.72rem;
            color: #888;
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }

        /* Customize */
        .customize-section {
            padding: 0 1rem 0.5rem;
            background-color: #fff;
        }

        .customize-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .customize-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.35rem 0.5rem;
        }

        .customize-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .customize-item input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: #F4845F;
            cursor: pointer;
            flex-shrink: 0;
        }

        .customize-item label {
            font-size: 0.72rem;
            color: #444;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            flex: 1;
        }

        .addon-price {
            color: #555;
            font-size: 0.7rem;
        }

        /* Action Buttons */
        .action-btns {
            padding: 0.85rem 1rem;
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
            background-color: #fff;
        }

        .btn-back {
            background-color: #C0392B;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem;
            font-size: 0.85rem;
            font-weight: 600;
            flex: 1;
            cursor: pointer;
            transition: filter 0.2s;
        }

        .btn-back:hover {
            filter: brightness(0.88);
        }

        .btn-add-cart {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem;
            font-size: 0.85rem;
            font-weight: 600;
            flex: 1;
            cursor: pointer;
            transition: filter 0.2s;
        }

        .btn-add-cart:hover {
            filter: brightness(0.92);
        }

        /* Bottom Nav */
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
            cursor: pointer;
        }

        .nav-item i {
            font-size: 1.3rem;
        }

        .nav-item.active {
            color: #F4845F;
        }
    </style>
</head>

<body>
    <div class="app-wrapper">

        {{-- Header --}}
        <div class="top-header">
            <div class="logo-circle">🍑</div>
            <div class="business-info">
                <h2>Peachy</h2>
                <p>Cakes and Deli Cafe</p>
            </div>
        </div>

        {{-- Search --}}
        <div class="search-bar">
            <div class="input-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input type="text" placeholder="Search..." id="searchInput">
            </div>
            <button class="btn-cancel-search" onclick="document.getElementById('searchInput').value=''">Cancel</button>
        </div>

        {{-- Item Image --}}
        @if(isset($item) && $item->image)
        <img src="{{ asset($item->image) }}" alt="{{ $item->name }}" class="item-image">
        @else
        <div class="item-image-placeholder">
            <i class="bi bi-image"></i>
        </div>
        @endif

        {{-- Item Info --}}
        <div class="item-info">
            <div class="item-name-row">
                <span class="item-name">{{ isset($item) ? $item->name : 'Item Name' }}</span>
                <span class="item-price">P {{ isset($item) ? number_format($item->price, 2) : '0.00' }}</span>
            </div>
            <p class="item-description">{{ isset($item) ? $item->description : 'Item description here.' }}</p>
        </div>

        {{-- Customize --}}
        <form action="{{ route('customer.cart.add') }}" method="POST" id="addToCartForm">
            @csrf
            <input type="hidden" name="item_id" value="{{ isset($item) ? $item->id : '' }}">

            <div class="customize-section">
                @if(isset($item) && $item->options->count() > 0)
                <p class="customize-title">Customize</p>
                <div class="customize-grid">
                    @foreach($item->options as $option)
                    <div class="customize-item">
                        <input type="checkbox" name="options[]" value="{{ $option->id }}" id="option{{ $option->id }}">
                        <label for="option{{ $option->id }}">
                            {{ $option->name }}
                            @if($option->additional_price > 0)
                            <span class="addon-price">+₱{{ number_format($option->additional_price, 0) }}</span>
                            @else
                            <span class="addon-price">Free</span>
                            @endif
                        </label>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Action Buttons --}}
            <div class="action-btns">
                <button type="button" class="btn-back" onclick="window.history.back()">Back</button>
                <button type="submit" class="btn-add-cart">Add to cart</button>
            </div>

        </form>

        {{-- Bottom Nav --}}
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
</body>

</html>
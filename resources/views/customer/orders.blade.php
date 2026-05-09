<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - Peachy</title>
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

        .content-section {
            background-color: #F4845F;
            flex: 1;
            padding: 0.75rem;
        }

        /* Tab Navigation */
        .tab-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .tab-btn {
            background: transparent;
            border: 2px solid rgba(255, 255, 255, 0.4);
            color: rgba(255, 255, 255, 0.7);
            border-radius: 20px;
            padding: 0.35rem 1rem;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: white;
            color: #C0392B;
            border-color: white;
        }

        /* Status Progress */
        .status-card {
            background: white;
            border-radius: 12px;
            padding: 0.75rem;
            margin-bottom: 0.6rem;
        }

        .order-number-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.6rem;
        }

        .order-number {
            font-size: 0.75rem;
            font-weight: 700;
            color: #F4845F;
        }

        .order-type-badge {
            font-size: 0.65rem;
            background: #fde8de;
            color: #C0392B;
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
            font-weight: 600;
        }

        .progress-track {
            display: flex;
            align-items: center;
            padding: 0.3rem 0;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .step-circle {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid #ddd;
            background-color: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .step-circle.done {
            background-color: #4CAF50;
            border-color: #4CAF50;
        }

        .step-circle.done::after {
            content: '✓';
            color: white;
            font-size: 0.55rem;
            font-weight: 700;
        }

        .step-circle.active {
            background-color: #F4845F;
            border-color: #F4845F;
            animation: pulse 1.5s infinite;
        }

        .step-circle.active::after {
            content: '';
            width: 6px;
            height: 6px;
            background: white;
            border-radius: 50%;
        }

        @keyframes pulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(244, 132, 95, 0.4);
            }

            50% {
                box-shadow: 0 0 0 6px rgba(244, 132, 95, 0);
            }
        }

        .step-label {
            font-size: 0.62rem;
            color: #aaa;
            margin-top: 4px;
            font-weight: 500;
        }

        .step-label.active {
            color: #F4845F;
            font-weight: 700;
        }

        .step-label.done {
            color: #4CAF50;
            font-weight: 600;
        }

        .progress-line {
            flex: 1;
            height: 3px;
            background-color: #eee;
            margin-bottom: 18px;
            border-radius: 2px;
        }

        .progress-line.done {
            background-color: #4CAF50;
        }

        /* Order Items */
        .order-item-card {
            background: white;
            border-radius: 12px;
            padding: 0.6rem;
            margin-bottom: 0.5rem;
        }

        .item-row {
            display: flex;
            gap: 0.6rem;
            align-items: center;
        }

        .item-img {
            width: 55px;
            height: 55px;
            border-radius: 8px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }

        .item-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .item-details {
            flex: 1;
            min-width: 0;
        }

        .item-details h6 {
            font-size: 0.8rem;
            font-weight: 600;
            color: #333;
            margin: 0 0 0.1rem;
        }

        .item-options {
            font-size: 0.65rem;
            color: #F4845F;
            margin: 0 0 0.1rem;
        }

        .item-price {
            font-size: 0.72rem;
            color: #888;
        }

        .item-subtotal {
            font-size: 0.82rem;
            font-weight: 700;
            color: #333;
            flex-shrink: 0;
        }

        /* Order Summary */
        .order-summary {
            background: white;
            border-radius: 12px;
            padding: 0.65rem;
            margin-bottom: 0.6rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.2rem 0;
            font-size: 0.75rem;
        }

        .summary-row .label {
            color: #888;
        }

        .summary-row .value {
            color: #333;
            font-weight: 500;
        }

        .summary-row.total {
            border-top: 2px solid #F4845F;
            padding-top: 0.4rem;
            margin-top: 0.2rem;
        }

        .summary-row.total .label {
            font-weight: 700;
            color: #333;
        }

        .summary-row.total .value {
            font-weight: 700;
            color: #F4845F;
            font-size: 0.85rem;
        }

        .summary-row.discount .label,
        .summary-row.discount .value {
            color: #4CAF50;
        }

        /* History Items */
        .date-group {
            font-size: 0.68rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.85);
            margin: 0.6rem 0 0.3rem;
            padding-left: 0.2rem;
        }

        .history-card {
            background: white;
            border-radius: 12px;
            padding: 0.65rem;
            margin-bottom: 0.5rem;
            display: flex;
            gap: 0.6rem;
            align-items: center;
            text-decoration: none;
        }

        .history-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .history-icon.completed {
            background: linear-gradient(135deg, #4CAF50, #66BB6A);
        }

        .history-icon.cancelled {
            background: linear-gradient(135deg, #C0392B, #E74C3C);
        }

        .history-icon i {
            color: white;
            font-size: 1rem;
        }

        .history-info {
            flex: 1;
            min-width: 0;
        }

        .history-order-num {
            font-size: 0.72rem;
            font-weight: 700;
            color: #333;
        }

        .history-meta {
            font-size: 0.65rem;
            color: #888;
        }

        .history-discount {
            font-size: 0.62rem;
            color: #4CAF50;
            font-weight: 500;
        }

        .history-time {
            font-size: 0.6rem;
            color: #bbb;
        }

        .history-right {
            text-align: right;
            flex-shrink: 0;
        }

        .history-amount {
            font-size: 0.85rem;
            font-weight: 700;
            color: #333;
            display: block;
        }

        .history-receipt {
            font-size: 0.6rem;
            color: #F4845F;
            font-weight: 600;
        }

        /* Empty State */
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 2rem 1rem;
            text-align: center;
        }

        .empty-state i {
            font-size: 2.5rem;
            color: #eee;
            display: block;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.82rem;
            color: #aaa;
            margin-bottom: 0.75rem;
        }

        .empty-state a {
            color: #F4845F;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.82rem;
        }

        /* Game Button */
        .game-btn {
            display: block;
            background: white;
            color: #F4845F;
            border: none;
            border-radius: 12px;
            padding: 0.6rem;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            margin-top: 0.5rem;
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
            color: #aaa;
            font-size: 0.65rem;
            font-weight: 500;
            cursor: pointer;
        }

        .nav-item.active {
            color: #F4845F;
        }

        .nav-item i {
            font-size: 1.3rem;
        }
    </style>
</head>

<body>
    <div class="app-wrapper">
        <div class="top-header">
            <div class="logo-circle">🍑</div>
            <div class="business-info">
                <h2>Peachy</h2>
                <p>Cakes and Deli Cafe</p>
            </div>
        </div>

        <div class="content-section">

            {{-- Tab Buttons --}}
            <div class="tab-header">
                <button class="tab-btn active" onclick="switchTab(0, this)"><i class="bi bi-bag"></i> Current Order</button>
                <button class="tab-btn" onclick="switchTab(1, this)"><i class="bi bi-clock-history"></i> History</button>
            </div>

            {{-- ══════════ TAB 1: Current Order ══════════ --}}
            <div id="statusTab">
                @if(isset($currentOrder) && $currentOrder)
                @php
                $status = $currentOrder->status;
                $isPending = in_array($status, ['pending', 'preparing', 'serving']);
                $isPreparing = in_array($status, ['preparing', 'serving']);
                $isServing = $status === 'serving';
                @endphp

                {{-- Order Number + Type --}}
                <div class="status-card">
                    <div class="order-number-bar">
                        <span class="order-number">{{ $currentOrder->order_number }}</span>
                        <span class="order-type-badge">
                            <i class="bi {{ $currentOrder->type === 'dine_in' ? 'bi-shop' : 'bi-bag' }}"></i>
                            {{ $currentOrder->type === 'dine_in' ? 'Dine-in • Table ' . $currentOrder->table_number : 'Pickup' }}
                        </span>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="progress-track">
                        <div class="progress-step">
                            <div class="step-circle {{ $isPending ? ($isPreparing ? 'done' : 'active') : '' }}"></div>
                            <span class="step-label {{ $isPending ? ($isPreparing ? 'done' : 'active') : '' }}">Pending</span>
                        </div>
                        <div class="progress-line {{ $isPreparing ? 'done' : '' }}"></div>
                        <div class="progress-step">
                            <div class="step-circle {{ $isPreparing ? ($isServing ? 'done' : 'active') : '' }}"></div>
                            <span class="step-label {{ $isPreparing ? ($isServing ? 'done' : 'active') : '' }}">Preparing</span>
                        </div>
                        <div class="progress-line {{ $isServing ? 'done' : '' }}"></div>
                        <div class="progress-step">
                            <div class="step-circle {{ $isServing ? 'active' : '' }}"></div>
                            <span class="step-label {{ $isServing ? 'active' : '' }}">Serving</span>
                        </div>
                    </div>
                </div>

                {{-- Order Items --}}
                @foreach($currentOrder->items as $item)
                <div class="order-item-card">
                    <div class="item-row">
                        <div class="item-img">
                            @if($item->menuItem && $item->menuItem->image)
                            <img src="{{ asset($item->menuItem->image) }}" alt="{{ $item->item_name }}">
                            @else
                            <i class="bi bi-image" style="color:#ddd;font-size:1.2rem;"></i>
                            @endif
                        </div>
                        <div class="item-details">
                            <h6>{{ $item->item_name }} <span style="color:#888;font-weight:400;">x{{ $item->quantity }}</span></h6>
                            @if($item->options && $item->options->count() > 0)
                            <p class="item-options">{{ $item->options->pluck('option_name')->implode(', ') }}</p>
                            @endif
                            <span class="item-price">₱{{ number_format($item->item_price, 2) }} each</span>
                        </div>
                        <span class="item-subtotal">₱{{ number_format($item->subtotal, 2) }}</span>
                    </div>
                </div>
                @endforeach

                {{-- Order Summary --}}
                <div class="order-summary">
                    <div class="summary-row">
                        <span class="label">Payment</span>
                        <span class="value">{{ ucfirst($currentOrder->payment_method ?? 'Cash') }}</span>
                    </div>
                    <div class="summary-row">
                        <span class="label">Subtotal ({{ $currentOrder->items->count() }} items)</span>
                        <span class="value">₱{{ number_format($currentOrder->subtotal, 2) }}</span>
                    </div>
                    @if($currentOrder->discount_amount > 0)
                    <div class="summary-row discount">
                        <span class="label">Voucher Discount</span>
                        <span class="value">-₱{{ number_format($currentOrder->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    <div class="summary-row total">
                        <span class="label">Total</span>
                        <span class="value">₱{{ number_format($currentOrder->total, 2) }}</span>
                    </div>
                </div>

                <a href="{{ route('customer.game') }}" class="game-btn">
                    <i class="bi bi-dice-5"></i> Play Game While You Wait
                </a>

                @else
                <div class="empty-state">
                    <i class="bi bi-bag-check"></i>
                    <p>No active order right now</p>
                    <a href="{{ route('customer.menu') }}"><i class="bi bi-grid"></i> Browse Menu →</a>
                </div>
                @endif
            </div>

            {{-- ══════════ TAB 2: Order History ══════════ --}}
            <div id="historyTab" style="display:none;">
                @if(isset($orderHistory) && count($orderHistory) > 0)
                @php
                $grouped = $orderHistory->groupBy(function($order) {
                if ($order->created_at->isToday()) return 'Today';
                if ($order->created_at->isYesterday()) return 'Yesterday';
                return $order->created_at->format('M d, Y');
                });
                @endphp

                @foreach($grouped as $date => $orders)
                <p class="date-group">{{ $date }}</p>
                @foreach($orders as $order)
                <a href="{{ route('customer.receipt', $order->id) }}" class="history-card">
                    <div class="history-icon {{ $order->status === 'cancelled' ? 'cancelled' : 'completed' }}">
                        <i class="bi {{ $order->status === 'cancelled' ? 'bi-x-lg' : 'bi-check2' }}"></i>
                    </div>
                    <div class="history-info">
                        <span class="history-order-num">{{ $order->order_number }}</span>
                        <span class="history-meta">{{ $order->items_count }} item(s) • {{ strtoupper($order->status) }}</span>
                        @if($order->discount_amount > 0)
                        <span class="history-discount">Discount: -₱{{ number_format($order->discount_amount, 2) }}</span>
                        @endif
                        <span class="history-time">{{ $order->created_at->format('h:i A') }}</span>
                    </div>
                    <div class="history-right">
                        <span class="history-amount">₱{{ number_format($order->total, 2) }}</span>
                        <span class="history-receipt">Receipt →</span>
                    </div>
                </a>
                @endforeach
                @endforeach
                @else
                <div class="empty-state">
                    <i class="bi bi-clock-history"></i>
                    <p>No order history yet</p>
                    <a href="{{ route('customer.menu') }}">Start your first order →</a>
                </div>
                @endif
            </div>

        </div>

        {{-- Bottom Nav --}}
        <div class="bottom-nav">
            <a href="{{ route('customer.orders') }}" class="nav-item active">
                <i class="bi bi-receipt"></i><span>Orders</span>
            </a>
            <a href="{{ route('customer.menu') }}" class="nav-item">
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
            @if(session('order_type') === 'dine_in' && session('table_number'))
            <form action="{{ route('customer.help-request') }}" method="POST" style="display:contents;">
                @csrf
                <button type="submit" class="nav-item"
                    style="background:none;border:none;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;color:#F4845F;font-size:0.65rem;font-weight:500;">
                    <i class="bi bi-question-circle" style="font-size:1.3rem;"></i>
                    <span>Help</span>
                </button>
            </form>
            @else
            <span class="nav-item" style="opacity:0.3;cursor:not-allowed;">
                <i class="bi bi-question-circle"></i><span>Help</span>
            </span>
            @endif
        </div>
    </div>

    <script>
        function switchTab(index, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('statusTab').style.display = index === 0 ? 'block' : 'none';
            document.getElementById('historyTab').style.display = index === 1 ? 'block' : 'none';
        }

        // Auto-refresh every 5 seconds if there's an active order
        @if(isset($currentOrder) && $currentOrder)
        setTimeout(function() {
            location.reload();
        }, 5000);
        @endif
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
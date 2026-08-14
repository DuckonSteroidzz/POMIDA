<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Vouchers - Peachy</title>
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
            padding-bottom: 80px;
        }

        .top-header {
            background-color: #fff;
            padding: 0.75rem 1rem 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
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

        .page-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Voucher Card */
        .voucher-card {
            background: white;
            border-radius: 16px;
            margin-bottom: 0.75rem;
            overflow: hidden;
            position: relative;
        }

        .voucher-card.used {
            opacity: 0.6;
        }

        .voucher-card.not-yet-valid {
            border: 2px dashed #F4845F;
        }

        .voucher-card.valid-now {
            border: 2px solid #4CAF50;
        }

        .voucher-top {
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .voucher-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .voucher-icon.valid {
            background: linear-gradient(135deg, #4CAF50, #66BB6A);
        }

        .voucher-icon.pending {
            background: linear-gradient(135deg, #F4845F, #C0392B);
        }

        .voucher-icon.used-icon {
            background: #eee;
        }

        .voucher-info {
            flex: 1;
            min-width: 0;
        }

        .voucher-discount {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
        }

        .voucher-desc {
            font-size: 0.72rem;
            color: #888;
            margin: 0.1rem 0;
        }

        .voucher-min {
            font-size: 0.65rem;
            color: #aaa;
        }

        /* Divider with circles */
        .voucher-divider {
            display: flex;
            align-items: center;
            position: relative;
            padding: 0 0.5rem;
        }

        .voucher-divider::before {
            content: '';
            flex: 1;
            border-top: 2px dashed #eee;
        }

        .circle-left {
            width: 16px;
            height: 16px;
            background: #F4845F;
            border-radius: 50%;
            position: absolute;
            left: -8px;
        }

        .circle-right {
            width: 16px;
            height: 16px;
            background: #F4845F;
            border-radius: 50%;
            position: absolute;
            right: -8px;
        }

        .voucher-bottom {
            padding: 0.6rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .voucher-code {
            font-size: 1rem;
            font-weight: 700;
            color: #F4845F;
            letter-spacing: 2px;
            background: #fff9f6;
            border: 1.5px dashed #F4845F;
            border-radius: 8px;
            padding: 0.3rem 0.75rem;
        }

        .voucher-code.used-code {
            color: #aaa;
            border-color: #ddd;
            background: #f9f9f9;
            text-decoration: line-through;
        }

        .copy-small {
            background: #F4845F;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.3rem 0.65rem;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
        }

        .copy-small:disabled {
            background: #ddd;
            cursor: not-allowed;
        }

        /* Status Badge */
        .status-badge {
            position: absolute;
            top: 0.6rem;
            right: 0.6rem;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
        }

        .badge-valid {
            background: #d4edda;
            color: #4CAF50;
        }

        .badge-pending {
            background: #fff3cd;
            color: #E67E22;
        }

        .badge-used {
            background: #f0f0f0;
            color: #aaa;
        }

        /* Valid dates */
        .voucher-dates {
            font-size: 0.62rem;
            color: #888;
            padding: 0 1rem 0.6rem;
        }

        .voucher-dates span {
            color: #E67E22;
            font-weight: 600;
        }

        /* Empty State */
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 3rem 1rem;
            text-align: center;
        }

        .empty-state i {
            font-size: 3rem;
            color: #eee;
            display: block;
            margin-bottom: 0.75rem;
        }

        .empty-state p {
            font-size: 0.85rem;
            color: #aaa;
            margin-bottom: 0.75rem;
        }

        .empty-state a {
            color: #F4845F;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.82rem;
        }

        /* Points hint */
        .points-hint {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 0.65rem 0.85rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .points-hint i {
            color: white;
            font-size: 1.1rem;
        }

        .points-hint p {
            font-size: 0.72rem;
            color: white;
            margin: 0;
        }

        .points-hint strong {
            font-weight: 700;
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
            <p class="page-title"><i class="bi bi-ticket-perforated"></i> My Vouchers</p>

            {{-- Points Hint --}}
            <div class="points-hint">
                <i class="bi bi-star-fill"></i>
                <p>You have <strong>{{ auth()->user()->points }} pts</strong> — Spin the wheel to earn more vouchers!</p>
            </div>

            @if(isset($userVouchers) && count($userVouchers) > 0)
            @foreach($userVouchers as $uv)
            @php
            $voucher = $uv->voucher;
            $isUsed = $uv->is_used;
            $isValidNow = $voucher->valid_from
            ? today()->greaterThanOrEqualTo($voucher->valid_from)
            : true;
            $isExpired = $voucher->expires_at && $voucher->expires_at->isPast();

            $cardClass = $isUsed ? 'used' : ($isValidNow && !$isExpired ? 'valid-now' : 'not-yet-valid');
            $iconClass = $isUsed ? 'used-icon' : ($isValidNow ? 'valid' : 'pending');
            $iconEmoji = $isUsed ? '✓' : ($isValidNow ? '🎟️' : '⏳');

            $discountText = $voucher->discount_type === 'percent'
            ? $voucher->discount_value . '% OFF'
            : '₱' . number_format($voucher->discount_value, 2) . ' OFF';
            @endphp

            <div class="voucher-card {{ $cardClass }}">
                {{-- Status Badge --}}
                @if($isUsed)
                <span class="status-badge badge-used">USED</span>
                @elseif($isExpired)
                <span class="status-badge badge-used">EXPIRED</span>
                @elseif($isValidNow)
                <span class="status-badge badge-valid">✓ VALID NOW</span>
                @else
                <span class="status-badge badge-pending">⏳ NOT YET VALID</span>
                @endif

                <div class="voucher-top">
                    <div class="voucher-icon {{ $iconClass }}">
                        {{ $iconEmoji }}
                    </div>
                    <div class="voucher-info">
                        <p class="voucher-discount">{{ $discountText }}</p>
                        <p class="voucher-desc">{{ $voucher->description ?? 'Peachy Voucher' }}</p>
                        <p class="voucher-min">Min. order: ₱{{ number_format($voucher->minimum_order, 2) }}</p>
                    </div>
                </div>

                {{-- Dates --}}
                @if($voucher->valid_from)
                📅 Use from: <span>{{ \Carbon\Carbon::parse($voucher->valid_from)->format('M d, Y') }}</span>
                @endif
                @if($voucher->expires_at)
                — Until: <span>{{ $voucher->expires_at->format('M d, Y') }}</span>
                @else
                — <span style="color:#4CAF50;">No expiry</span>
                @endif
            </div>

            {{-- Divider --}}
            <div class="voucher-divider" style="margin: 0 0.75rem;">
                <div class="circle-left"></div>
                <div class="circle-right"></div>
            </div>

            {{-- Code + Copy --}}
            <div class="voucher-bottom">
                <span class="voucher-code {{ $isUsed ? 'used-code' : '' }}">
                    {{ $voucher->code }}
                </span>
                @if(!$isUsed && $isValidNow && !$isExpired)
                <button class="copy-small" onclick="copyVoucher('{{ $voucher->code }}', this)">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
                @else
                <button class="copy-small" disabled>
                    {{ $isUsed ? 'Used' : ($isExpired ? 'Expired' : 'Not yet') }}
                </button>
                @endif
            </div>
        </div>
        @endforeach
        @else
        <div class="empty-state">
            <i class="bi bi-ticket-perforated"></i>
            <p>No vouchers yet — spin the wheel to earn one!</p>
            <a href="{{ route('customer.game') }}"><i class="bi bi-dice-5"></i> Go to Spin Wheel →</a>
        </div>
        @endif
    </div>

    {{-- Bottom Nav --}}
    <div class="bottom-nav">
        <a href="{{ route('customer.orders') }}" class="nav-item">
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
        <a href="{{ route('customer.game') }}" class="nav-item">
            <i class="bi bi-dice-5"></i><span>Game</span>
        </a>
        <a href="{{ route('customer.more') }}" class="nav-item active">
            <i class="bi bi-three-dots"></i><span>More</span>
        </a>
    </div>
    </div>

    <script>
        function copyVoucher(code, btn) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function() {
                    btn.innerHTML = '✓ Copied!';
                    setTimeout(function() {
                        btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                    }, 2000);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = code;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                btn.innerHTML = '✓ Copied!';
                setTimeout(function() {
                    btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                }, 2000);
            }
        }
    </script>
</body>

</html>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart - Peachy</title>
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

        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 0.5rem 1rem;
            margin: 0 1rem 0.5rem;
            border-radius: 8px;
            font-size: 0.8rem;
            text-align: center;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 0.5rem 1rem;
            margin: 0 1rem 0.5rem;
            border-radius: 8px;
            font-size: 0.8rem;
        }

        .content-section {
            background-color: #F4845F;
            flex: 1;
            padding: 0.75rem;
            padding-bottom: 280px;
        }

        .section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: white;
            text-align: center;
            margin-bottom: 0.75rem;
        }

        .cart-item {
            background-color: white;
            border-radius: 12px;
            padding: 0.6rem;
            margin-bottom: 0.6rem;
            display: flex;
            gap: 0.6rem;
            align-items: flex-start;
        }

        .cart-item .img-wrapper {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
        }

        .cart-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-info h6 {
            font-size: 0.85rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 0.2rem;
        }

        .cart-item-info .price {
            font-size: 0.78rem;
            color: #F4845F;
            font-weight: 600;
            margin-bottom: 0.4rem;
        }

        .cart-item-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .qty-controls {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .qty-btn {
            background: #F4845F;
            color: white;
            border: none;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-weight: 600;
        }

        .item-total {
            font-size: 0.85rem;
            font-weight: 700;
            color: #333;
            margin-left: auto;
        }

        .bottom-fixed {
            position: fixed;
            bottom: 56px;
            width: 100%;
            max-width: 420px;
            background-color: white;
            border-top: 1px solid #eee;
            padding: 0.75rem 1rem;
            z-index: 100;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.4rem;
        }

        .total-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #333;
        }

        .total-amount {
            font-size: 1rem;
            font-weight: 700;
            color: #F4845F;
        }

        .action-btns {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .btn-back {
            background-color: #2e2e2e;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem;
            font-size: 0.82rem;
            font-weight: 600;
            flex: 1;
            text-decoration: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-proceed {
            background-color: #F4845F;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem;
            font-size: 0.82rem;
            font-weight: 600;
            flex: 2;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
        }

        .order-type-badge {
            background: #fde8de;
            color: #C0392B;
            padding: 0.3rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            text-align: center;
            margin-bottom: 0.5rem;
        }

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
            z-index: 101;
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

        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
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

        @if(session('success'))<div class="alert-success">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert-error">{{ $errors->first() }}</div>@endif

        <div class="content-section">
            <p class="section-title">Your Cart</p>
            @if(isset($cart) && count($cart) > 0)
            @foreach($cart as $itemId => $item)
            <div class="cart-item">
                <div class="img-wrapper">
                    @if(!empty($item['image']))<img src="{{ asset($item['image']) }}" alt="{{ $item['name'] }}">
                    @else<i class="bi bi-image" style="color:#ccc;font-size:1.5rem;"></i>@endif
                </div>
                <div class="cart-item-info">
                    <h6>{{ $item['name'] }}</h6>
                    @if(!empty($item['options']))
                    <p style="font-size:0.68rem;color:#888;margin:0;">
                        {{ implode(', ', array_column($item['options'], 'name')) }}
                    </p>
                    @endif
                    <p class="price">₱{{ number_format($item['price'], 2) }} each</p>
                    <div class="cart-item-controls">
                        <div class="qty-controls">
                            <form action="{{ route('customer.cart.update', $itemId) }}" method="POST" style="display:inline;">
                                @csrf @method('PUT')
                                <input type="hidden" name="quantity" value="{{ $item['quantity'] - 1 }}">
                                <button type="submit" class="qty-btn">−</button>
                            </form>
                            <form action="{{ route('customer.cart.update', $itemId) }}" method="POST" style="display:inline;">
                                @csrf @method('PUT')
                                <input type="number" name="quantity" value="{{ $item['quantity'] }}" min="1" max="999"
                                    style="width:45px;border:1px solid #ddd;border-radius:6px;padding:0.2rem 0.3rem;font-size:0.85rem;font-weight:600;text-align:center;font-family:'Poppins',sans-serif;-moz-appearance:textfield;appearance:textfield;"
                                    onchange="this.form.submit()">
                            </form>
                            <form action="{{ route('customer.cart.update', $itemId) }}" method="POST" style="display:inline;">
                                @csrf @method('PUT')
                                <input type="hidden" name="quantity" value="{{ $item['quantity'] + 1 }}">
                                <button type="submit" class="qty-btn">+</button>
                            </form>
                        </div>
                        <span class="item-total">₱{{ number_format($item['price'] * $item['quantity'], 2) }}</span>
                    </div>
                </div>
            </div>
            @endforeach
            @else
            <div style="background:white;border-radius:12px;padding:2rem;text-align:center;color:#aaa;">
                <i class="bi bi-cart-x" style="font-size:2rem;color:#ddd;display:block;margin-bottom:0.5rem;"></i>
                Your cart is empty.<br>
                <a href="{{ route('customer.menu') }}" style="color:#F4845F;font-weight:600;text-decoration:none;">Go to menu →</a>
            </div>
            @endif
        </div>

        @if(isset($cart) && count($cart) > 0)
        <div class="bottom-fixed">
            <form action="{{ route('customer.place-order') }}" method="POST" id="mainOrderForm">
                @csrf
                @php $idx = 0; @endphp
                @foreach($cart as $itemId => $item)
                <input type="hidden" name="items[{{ $idx }}][menu_item_id]" value="{{ $item['menu_item_id'] ?? $itemId }}">
                <input type="hidden" name="items[{{ $idx }}][quantity]" value="{{ $item['quantity'] }}">
                @php $idx++; @endphp
                @endforeach

                {{-- Order type from session --}}
                @php
                $sessionOrderType = session('order_type', Auth::check() ? 'pick_up' : 'dine_in');
                $sessionBranchId = session('branch_id');
                $sessionBranch = $sessionBranchId ? \App\Models\Branch::find($sessionBranchId) : null;
                $sessionTable = session('table_number');
                @endphp

                <input type="hidden" name="order_type" value="{{ $sessionOrderType }}">
                <input type="hidden" name="payment_method" value="cash">
                <input type="hidden" name="branch_id" value="{{ $sessionBranchId }}">

                @if($sessionOrderType === 'dine_in')
                <input type="hidden" name="table_number" value="{{ $sessionTable }}">
                @endif

                {{-- Order type badge --}}
                <div class="order-type-badge">
                    @if($sessionOrderType === 'dine_in')
                    <i class="bi bi-shop"></i> Dine-in
                    @if($sessionTable) • Table {{ $sessionTable }}@endif
                    @if($sessionBranch) • {{ $sessionBranch->name }}@endif
                    @else
                    <i class="bi bi-bag"></i> Pickup
                    @if($sessionBranch) • {{ $sessionBranch->name }}@endif
                    @endif
                </div>

                {{-- No branch warning --}}
                @if(!$sessionBranchId)
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:0.5rem 0.75rem;margin-bottom:0.5rem;font-size:0.72rem;color:#856404;text-align:center;">
                    <i class="bi bi-exclamation-triangle"></i>
                    No branch selected!
                    <a href="{{ route('customer.menu') }}" style="color:#F4845F;font-weight:700;">Go to menu to select branch →</a>
                </div>
                @endif

                {{-- Change branch link --}}
                @if($sessionBranchId && $sessionOrderType !== 'dine_in')
                <div style="font-size:0.68rem;color:#888;text-align:center;margin-bottom:0.4rem;">
                    <a href="{{ route('customer.menu') }}" style="color:#F4845F;">
                        <i class="bi bi-building"></i> Change pick-up branch
                    </a>
                </div>
                @endif

                <div style="margin-bottom:0.4rem;">
                    <div style="display:flex;gap:0.4rem;align-items:center;">
                        <input type="text" id="voucherInput" placeholder="Enter voucher code"
                            style="flex:1;border:1px solid #ddd;border-radius:8px;padding:0.45rem 0.75rem;font-size:0.78rem;font-family:'Poppins',sans-serif;text-transform:uppercase;min-width:0;">
                        <button type="button" onclick="applyVoucher()"
                            style="background:#F4845F;color:white;border:none;border-radius:8px;padding:0.45rem 0.9rem;font-weight:600;white-space:nowrap;cursor:pointer;font-family:'Poppins',sans-serif;font-size:0.78rem;flex-shrink:0;">
                            Apply
                        </button>
                    </div>
                    <div id="voucherMsg" style="font-size:0.72rem;margin-top:0.25rem;"></div>
                </div>

                <input type="hidden" name="voucher_code_confirmed" id="voucher_code_hidden" value="">
                <input type="hidden" name="discount_amount" id="discountAmount" value="0">

                <div class="total-row">
                    <span class="total-label">Subtotal</span>
                    <span class="total-amount">₱{{ number_format($total, 2) }}</span>
                </div>
                <div class="total-row" id="discountRow" style="display:none;">
                    <span class="total-label" style="color:#4CAF50;">Discount</span>
                    <span style="font-weight:700;color:#4CAF50;" id="discountDisplay">-₱0.00</span>
                </div>
                <div class="total-row">
                    <span class="total-label">Total</span>
                    <span class="total-amount" id="finalTotal">₱{{ number_format($total, 2) }}</span>
                </div>

                <div class="action-btns">
                    <a href="{{ route('customer.menu') }}" class="btn-back">Menu</a>
                    <button type="submit" class="btn-proceed"><i class="bi bi-check-circle"></i> Place Order</button>
                </div>
            </form>
        </div>
        @endif

        {{-- Bottom Nav - Always Visible --}}
        <div class="bottom-nav">
            <a href="{{ route('customer.orders') }}" class="nav-item">
                <i class="bi bi-receipt"></i><span>Orders</span>
            </a>
            <a href="{{ route('customer.menu') }}" class="nav-item">
                <i class="bi bi-grid"></i><span>Menu</span>
            </a>
            <a href="{{ route('customer.cart') }}" class="nav-item active" style="position:relative;">
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
            <a href="#" class="nav-item" onclick="showHelp()">
                <i class="bi bi-question-circle"></i><span>Help</span>
            </a>
        </div>
    </div>

    <script>
        var currentSubtotal = @json($total);



        window.addEventListener('load', function() {
            var savedVoucher = localStorage.getItem('peachy_voucher');
            if (savedVoucher) {
                document.getElementById('voucherInput').value = savedVoucher;
                applyVoucher();
            }
        });

        function applyVoucher() {
            var code = document.getElementById('voucherInput').value.trim().toUpperCase();
            var msg = document.getElementById('voucherMsg');

            if (!code) {
                msg.textContent = 'Please enter a voucher code.';
                msg.style.color = '#C0392B';
                return;
            }

            localStorage.setItem('peachy_voucher', code);

            fetch('/customer/apply-voucher', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        code: code,
                        subtotal: currentSubtotal
                    })
                })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (data.success) {
                        msg.style.color = '#4CAF50';
                        msg.textContent = '✓ ' + data.message;
                        document.getElementById('voucher_code_hidden').value = code;
                        document.getElementById('discountAmount').value = data.discount;
                        document.getElementById('discountRow').style.display = 'flex';
                        document.getElementById('discountDisplay').textContent = '-₱' + data.discount.toFixed(2);
                        document.getElementById('finalTotal').textContent = '₱' + data.final_total.toFixed(2);
                    } else {
                        msg.style.color = '#C0392B';
                        msg.textContent = '✗ ' + data.message;
                        document.getElementById('voucher_code_hidden').value = '';
                        document.getElementById('discountAmount').value = 0;
                        document.getElementById('discountRow').style.display = 'none';
                        document.getElementById('finalTotal').textContent = '₱' + currentSubtotal.toFixed(2);
                        localStorage.removeItem('peachy_voucher');
                    }
                })
                .catch(function() {
                    msg.textContent = 'Error applying voucher.';
                    msg.style.color = '#C0392B';
                });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
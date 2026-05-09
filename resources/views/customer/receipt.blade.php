<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - Peachy</title>
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

        .receipt-header {
            background: linear-gradient(135deg, #F4845F, #C0392B);
            color: white;
            padding: 1.5rem 1rem;
            text-align: center;
        }

        .receipt-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .receipt-header p {
            font-size: 0.75rem;
            opacity: 0.9;
        }

        .receipt-body {
            padding: 1rem;
            flex: 1;
        }

        .receipt-card {
            background: white;
            border: 2px dashed #F4845F;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 0.3rem 0;
            font-size: 0.78rem;
        }

        .receipt-row.header {
            font-weight: 700;
            border-bottom: 1px solid #eee;
            padding-bottom: 0.5rem;
            margin-bottom: 0.3rem;
        }

        .receipt-row.total {
            font-weight: 700;
            font-size: 0.95rem;
            border-top: 2px solid #F4845F;
            padding-top: 0.5rem;
            margin-top: 0.3rem;
        }

        .label {
            color: #888;
        }

        .value {
            color: #333;
            font-weight: 500;
        }

        .highlight {
            color: #F4845F;
            font-weight: 700;
        }

        .discount {
            color: #4CAF50;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
        }

        .btn-back {
            display: block;
            background: #2e2e2e;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.7rem;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            margin: 1rem;
        }
    </style>
</head>

<body>
    <div class="app-wrapper">
        <div class="receipt-header">
            <h2>🍑 Peachy</h2>
            <p>Cakes and Deli Cafe</p>
            <p style="margin-top:0.5rem;font-size:0.85rem;font-weight:600;">RECEIPT</p>
        </div>
        <div class="receipt-body">
            <div class="receipt-card">
                <div class="receipt-row">
                    <span class="label">Order #</span>
                    <span class="value">{{ $order->order_number }}</span>
                </div>
                <div class="receipt-row">
                    <span class="label">Date</span>
                    <span class="value">{{ $order->created_at->format('M d, Y h:i A') }}</span>
                </div>
                <div class="receipt-row">
                    <span class="label">Type</span>
                    <span class="value">{{ $order->type === 'dine_in' ? 'Dine-in (Table ' . $order->table_number . ')' : 'Pickup' }}</span>
                </div>
                <div class="receipt-row">
                    <span class="label">Payment</span>
                    <span class="value">{{ ucfirst($order->payment_method ?? 'Cash') }}</span>
                </div>
                <div class="receipt-row">
                    <span class="label">Status</span>
                    <span class="status-badge" style="background:{{ $order->status === 'completed' ? '#4CAF50' : '#C0392B' }};">
                        {{ strtoupper($order->status) }}
                    </span>
                </div>
            </div>
            <div class="receipt-card">
                <div class="receipt-row header">
                    <span>Item</span>
                    <span>Amount</span>
                </div>
                @foreach($order->items as $item)
                <div style="padding:0.3rem 0;">
                    <div style="display:flex;justify-content:space-between;font-size:0.78rem;">
                        <span>{{ $item->quantity }}x {{ $item->item_name }}</span>
                        <span>₱{{ number_format($item->subtotal, 2) }}</span>
                    </div>
                    @if($item->options && $item->options->count() > 0)
                    <div style="font-size:0.65rem;color:#888;padding-left:1.5rem;">{{ $item->options->pluck('option_name')->implode(', ') }}</div>
                    @endif
                </div>
                @endforeach
                <div class="receipt-row" style="border-top:1px solid #eee;padding-top:0.4rem;margin-top:0.3rem;">
                    <span class="label">Subtotal</span>
                    <span class="value">₱{{ number_format($order->subtotal, 2) }}</span>
                </div>
                @if($order->discount_amount > 0)
                <div class="receipt-row">
                    <span class="label discount">Voucher Discount</span>
                    <span class="value discount">-₱{{ number_format($order->discount_amount, 2) }}</span>
                </div>
                @endif
                <div class="receipt-row total">
                    <span>TOTAL</span>
                    <span class="highlight">₱{{ number_format($order->total, 2) }}</span>
                </div>
            </div>
            <p style="text-align:center;font-size:0.75rem;color:#aaa;margin-top:1rem;">Thank you for dining with us! 🍑</p>
        </div>
        <a href="{{ route('customer.orders') }}" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Orders
        </a>
    </div>
</body>

</html>
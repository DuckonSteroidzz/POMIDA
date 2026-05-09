@extends('admin.layout')

@section('title', 'Completed Orders - Peachy Admin')

@section('content')

<p class="page-title">Completed Orders</p>

@if(session('success'))
<div class="alert-success-custom">
    <i class="bi bi-check-circle"></i> {{ session('success') }}
</div>
@endif

<div class="content-card" style="margin-bottom:1rem;">
    <form method="GET" action="{{ route('admin.completed-orders') }}" style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
        <div>
            <label class="form-label-custom">Date From</label>
            <input type="date" name="date_from" class="form-control-custom" value="{{ request('date_from') }}" style="margin-bottom:0; width:160px;">
        </div>
        <div>
            <label class="form-label-custom">Date To</label>
            <input type="date" name="date_to" class="form-control-custom" value="{{ request('date_to') }}" style="margin-bottom:0; width:160px;">
        </div>
        <div>
            <label class="form-label-custom">Type</label>
            <select name="type" class="form-control-custom" style="margin-bottom:0; width:130px;">
                <option value="">All Types</option>
                <option value="dine_in" {{ request('type') == 'dine_in' ? 'selected' : '' }}>Dine In</option>
                <option value="pick_up" {{ request('type') == 'pick_up' ? 'selected' : '' }}>Pick Up</option>
            </select>
        </div>
        <div>
            <label class="form-label-custom">Status</label>
            <select name="status" class="form-control-custom" style="margin-bottom:0; width:130px;">
                <option value="">All</option>
                <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
            </select>
        </div>
        <button type="submit" class="btn-primary-custom" style="padding: 0.55rem 1.25rem;">
            <i class="bi bi-funnel"></i> Filter
        </button>
    </form>
</div>

<div class="content-card" style="overflow-x: auto;">
    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead>
            <tr style="background:#F4845F;color:white;">
                <th style="padding:0.6rem;text-align:left;">Date</th>
                <th style="padding:0.6rem;text-align:left;">Order #</th>
                <th style="padding:0.6rem;text-align:center;">Type</th>
                <th style="padding:0.6rem;text-align:left;">Items</th>
                <th style="padding:0.6rem;text-align:right;">Subtotal</th>
                <th style="padding:0.6rem;text-align:right;">Discount</th>
                <th style="padding:0.6rem;text-align:right;">Total</th>
                <th style="padding:0.6rem;text-align:center;">Payment</th>
                <th style="padding:0.6rem;text-align:center;">Status</th>
                <th style="padding:0.6rem;text-align:center;">Print</th>
            </tr>
        </thead>
        <tbody>
            @if(count($orders) > 0)
                @foreach($orders as $order)
                @php
                    $statusColor = $order->status === 'completed' ? '#4CAF50' : '#C0392B';
                    $statusLabel = ucfirst($order->status);
                    $typeLabel = match($order->type) {
                        'dine_in' => 'Dine In',
                        'pick_up' => 'Pickup',
                        'walk_in' => 'Walk-in',
                        default => $order->type,
                    };
                @endphp
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:0.6rem;font-size:0.75rem;">{{ $order->created_at->format('M d, Y h:i A') }}</td>
                    <td style="padding:0.6rem;font-weight:600;color:#F4845F;">{{ $order->order_number }}</td>
                    <td style="padding:0.6rem;text-align:center;">
                        <span style="background:#F4845F;color:white;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.7rem;font-weight:600;">
                            {{ $typeLabel }}
                        </span>
                    </td>
                    <td style="padding:0.6rem;font-size:0.75rem;">
                        @foreach($order->items as $item)
                            {{ $item->quantity }}x {{ $item->item_name }}@if(!$loop->last), @endif
                        @endforeach
                    </td>
                    <td style="padding:0.6rem;text-align:right;">₱{{ number_format($order->subtotal, 2) }}</td>
                    <td style="padding:0.6rem;text-align:right;color:#4CAF50;">
                        {{ $order->discount_amount > 0 ? '-₱' . number_format($order->discount_amount, 2) : '—' }}
                    </td>
                    <td style="padding:0.6rem;text-align:right;font-weight:700;">₱{{ number_format($order->total, 2) }}</td>
                    <td style="padding:0.6rem;text-align:center;">{{ ucfirst($order->payment_method ?? 'cash') }}</td>
                    <td style="padding:0.6rem;text-align:center;">
                        <span style="background:{{ $statusColor }};color:white;padding:0.15rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:600;">
                            {{ $statusLabel }}
                        </span>
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        <button onclick="printReceipt({{ $order->id }})" style="background:#F4845F;color:white;padding:0.2rem 0.6rem;border-radius:6px;font-size:0.7rem;font-weight:600;border:none;cursor:pointer;">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </td>
                </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="10" style="text-align:center;color:#aaa;padding:2rem;">No orders found</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

@endsection

@push('scripts')
<script>
function printReceipt(orderId) {
    var printWindow = window.open('/admin/receipt/' + orderId, '_blank');
    printWindow.addEventListener('load', function() {
        setTimeout(function() {
            printWindow.print();
        }, 500);
    });
}
</script>
@endpush
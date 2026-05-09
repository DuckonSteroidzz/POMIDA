@extends('admin.layout')

@section('title', 'Home - Peachy Admin')

@section('content')



{{-- Customer Assistance Section --}}
@if(isset($helpRequests) && $helpRequests->count() > 0)
<div class="content-card" style="margin-bottom:1rem;border-left:4px solid #C0392B;">
    <p style="font-size:0.9rem;font-weight:700;color:#C0392B;margin-bottom:0.75rem;">
        <i class="bi bi-bell-fill"></i> Customer Assistance
        <span style="background:#C0392B;color:white;border-radius:20px;padding:0.1rem 0.5rem;font-size:0.72rem;margin-left:0.5rem;">
            {{ $helpRequests->count() }}
        </span>
    </p>
    @foreach($helpRequests as $help)
    <div style="background:{{ $help->status === 'pending' ? '#fff3cd' : '#d4edda' }};border-radius:10px;padding:0.65rem 0.85rem;margin-bottom:0.5rem;display:flex;justify-content:space-between;align-items:center;gap:0.5rem;flex-wrap:wrap;">
        <div>
            <p style="font-size:0.82rem;font-weight:700;color:#333;margin:0;">
                🙋 Table {{ $help->table_number }}
                <span style="font-size:0.68rem;font-weight:400;color:#888;margin-left:0.4rem;">
                    {{ $help->branch->name ?? '' }}
                </span>
            </p>
            <p style="font-size:0.7rem;color:#666;margin:0;">
                {{ $help->status === 'pending' ? '⏳ Waiting for assistance' : '🔧 Being assisted' }}
                • {{ $help->requested_at?->diffForHumans() }}
                @if($help->order) • Order #{{ $help->order->order_number }} @endif
            </p>
        </div>
        <div style="display:flex;gap:0.4rem;flex-shrink:0;">
            @if($help->status === 'pending')
            <form action="{{ route('admin.help-requests.assist', $help->id) }}" method="POST">
                @csrf @method('PUT')
                <button type="submit" style="background:#F4845F;color:white;border:none;border-radius:6px;padding:0.3rem 0.65rem;font-size:0.72rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
                    Assist
                </button>
            </form>
            @endif
            <form action="{{ route('admin.help-requests.resolve', $help->id) }}" method="POST">
                @csrf @method('PUT')
                <button type="submit" style="background:#4CAF50;color:white;border:none;border-radius:6px;padding:0.3rem 0.65rem;font-size:0.72rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
                    Done ✓
                </button>
            </form>
        </div>
    </div>
    @endforeach
</div>
@endif

<p class="page-title">Active Orders</p>

<div class="content-card">
    @if(isset($pendingOrders) && count($pendingOrders) > 0)
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
        @foreach($pendingOrders as $order)
        @php
        $statusColor = match($order->status) {
        'pending' => '#F4845F',
        'preparing' => '#E67E22',
        'serving' => '#27AE60',
        default => '#888',
        };

        // Check kung may help request ang table na ito
        $needsHelp = isset($helpRequests) && $helpRequests->contains(function($h) use ($order) {
        return $h->branch_id == $order->branch_id
        && $h->table_number == $order->table_number
        && $order->type === 'dine_in';
        });
        @endphp
        <div style="background-color: {{ $statusColor }}; border-radius: 12px; padding: 1rem; color: white;
    {{ $needsHelp ? 'border: 3px solid #fff200; box-shadow: 0 0 15px rgba(255,230,0,0.8);' : '' }}">

            @if($needsHelp)
            <div style="background:#fff200;color:#333;border-radius:6px;padding:0.3rem 0.6rem;margin-bottom:0.5rem;font-size:0.72rem;font-weight:700;text-align:center;animation:pulse 1s infinite;">
                🚨 TABLE NEEDS ASSISTANCE!
            </div>
            @endif
            <div style="background-color: rgba(255,255,255,0.2); border-radius: 6px; padding: 0.5rem 0.75rem; margin-bottom: 0.75rem;">
                <p style="font-size: 0.7rem; font-weight: 600; margin: 0; text-transform: uppercase;">
                    @if($order->type === 'dine_in')
                    <i class="bi bi-shop"></i> Dine-in — Table {{ $order->table_number ?? 'N/A' }}
                    @elseif($order->type === 'pick_up')
                    <i class="bi bi-bag"></i> Pickup
                    @else
                    <i class="bi bi-person-walking"></i> Walk-in
                    @endif
                </p>
                <p style="font-size: 0.65rem; margin: 0; opacity: 0.9;">Order #{{ $order->order_number }}</p>
                <p style="font-size: 0.65rem; margin: 0.2rem 0 0; opacity: 0.9;">
                    Status: <strong style="text-transform: uppercase;">{{ $order->status }}</strong>
                </p>
            </div>

            <div style="background-color: rgba(255,255,255,0.15); border-radius: 6px; padding: 0.5rem 0.75rem; margin-bottom: 0.75rem;">
                @foreach($order->items as $item)
                <p style="font-size: 0.75rem; margin: 0.15rem 0;">
                    <strong>{{ $item->quantity }}x</strong> {{ $item->item_name }}
                    <span style="float: right; font-weight: 600;">₱{{ number_format($item->subtotal, 2) }}</span>
                </p>
                @endforeach
            </div>

            <div style="padding-top: 0.5rem; border-top: 1px solid rgba(255,255,255,0.3); margin-bottom: 0.75rem;">
                <div style="display: flex; justify-content: space-between; font-size: 0.75rem;">
                    <span>Subtotal:</span>
                    <span>₱{{ number_format($order->subtotal, 2) }}</span>
                </div>
                @if($order->discount_amount > 0)
                <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: #d4edda;">
                    <span>Discount:</span>
                    <span>-₱{{ number_format($order->discount_amount, 2) }}</span>
                </div>
                @endif
                <div style="display: flex; justify-content: space-between; font-size: 1rem; font-weight: 700; margin-top: 0.25rem;">
                    <span>Total:</span>
                    <span>₱{{ number_format($order->total, 2) }}</span>
                </div>
            </div>

            <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                @if($order->status === 'pending')
                <form action="{{ route('admin.orders.prepare', $order->id) }}" method="POST" style="flex: 1; margin: 0;">
                    @csrf @method('PUT')
                    <button type="submit" style="background:#E67E22;color:white;border:none;border-radius:6px;padding:0.5rem;font-size:0.72rem;font-weight:600;cursor:pointer;width:100%;font-family:'Poppins',sans-serif;">
                        <i class="bi bi-fire"></i> Prepare
                    </button>
                </form>
                @endif
                @if($order->status === 'preparing')
                <form action="{{ route('admin.orders.serve', $order->id) }}" method="POST" style="flex: 1; margin: 0;">
                    @csrf @method('PUT')
                    <button type="submit" style="background:#27AE60;color:white;border:none;border-radius:6px;padding:0.5rem;font-size:0.72rem;font-weight:600;cursor:pointer;width:100%;font-family:'Poppins',sans-serif;">
                        <i class="bi bi-cup-hot"></i> Serving
                    </button>
                </form>
                @endif
                @if(in_array($order->status, ['pending', 'preparing', 'serving']))
                <form action="{{ route('admin.orders.complete', $order->id) }}" method="POST" style="flex: 1; margin: 0;" onsubmit="return confirm('Complete this order? Inventory will auto-deduct.')">
                    @csrf @method('PUT')
                    <button type="submit" style="background:#4CAF50;color:white;border:none;border-radius:6px;padding:0.5rem;font-size:0.72rem;font-weight:600;cursor:pointer;width:100%;font-family:'Poppins',sans-serif;">
                        <i class="bi bi-check-circle"></i> Complete
                    </button>
                </form>
                @endif
                <form action="{{ route('admin.orders.cancel', $order->id) }}" method="POST" style="margin: 0;" onsubmit="return confirm('Cancel this order?')">
                    @csrf @method('PUT')
                    <button type="submit" style="background:#C0392B;color:white;border:none;border-radius:6px;padding:0.5rem 0.75rem;font-size:0.72rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </form>
            </div>

            <p style="font-size: 0.65rem; opacity: 0.8; margin: 0.5rem 0 0; text-align: center;">
                {{ $order->created_at->diffForHumans() }}
            </p>
        </div>
        @endforeach
    </div>
    @else
    <div style="text-align: center; padding: 3rem 1rem; color: #aaa;">
        <i class="bi bi-inbox" style="font-size: 3rem; display: block; margin-bottom: 0.5rem;"></i>
        <p style="font-size: 0.9rem; margin: 0;">No active orders</p>
        <p style="font-size: 0.75rem; margin: 0.25rem 0 0;">New orders from customers will appear here</p>
    </div>
    @endif
</div>
<script>
    setTimeout(function() {
        location.reload();
    }, 5000);

    // CSS animation para sa pulse
    var style = document.createElement('style');
    style.innerHTML = '@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.5} }';
    document.head.appendChild(style);
</script>
@endsection
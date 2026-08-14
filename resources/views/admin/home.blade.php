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
                <i class="bi bi-hand-index-thumb"></i> Table {{ $help->table_number }}
                <span style="font-size:0.68rem;font-weight:400;color:#888;margin-left:0.4rem;">
                    {{ $help->branch->name ?? '' }}
                </span>
            </p>
            <p style="font-size:0.7rem;color:#666;margin:0;">
                @if($help->status === 'pending')
                    <i class="bi bi-hourglass-split"></i> Waiting for assistance
                @else
                    <i class="bi bi-tools"></i> Being assisted
                @endif
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

        // Saved Senior/PWD details of a logged-in pick-up customer (read-only for staff).
        $discountCustomer = $order->customer ? [
        'pwd_name'     => $order->customer->pwd_name,
        'pwd_card'     => $order->customer->pwd_card_number,
        'pwd_image'    => \App\Support\Img::url($order->customer->pwd_image),
        'senior_name'  => $order->customer->senior_name,
        'senior_card'  => $order->customer->senior_card_number,
        'senior_image' => \App\Support\Img::url($order->customer->senior_image),
        ] : null;
        @endphp
        <div style="background-color: {{ $statusColor }}; border-radius: 12px; padding: 1rem; color: white;
    {{ $needsHelp ? 'border: 3px solid #fff200; box-shadow: 0 0 15px rgba(255,230,0,0.8);' : '' }}">

            @if($needsHelp)
            <div style="background:#fff200;color:#333;border-radius:6px;padding:0.3rem 0.6rem;margin-bottom:0.5rem;font-size:0.72rem;font-weight:700;text-align:center;animation:pulse 1s infinite;">
                <i class="bi bi-exclamation-octagon-fill"></i> TABLE NEEDS ASSISTANCE!
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
                @if($item->options->isNotEmpty())
                <p style="font-size: 0.65rem; margin: 0 0 0.25rem 1.1rem; opacity: 0.85;">
                    + {{ $item->options->pluck('name')->implode(', ') }}
                </p>
                @endif
                @endforeach
            </div>

            <div style="padding-top: 0.5rem; border-top: 1px solid rgba(255,255,255,0.3); margin-bottom: 0.75rem;">
                <div style="display: flex; justify-content: space-between; font-size: 0.75rem;">
                    <span>Subtotal:</span>
                    <span>₱{{ number_format($order->subtotal, 2) }}</span>
                </div>
                @if($order->discount_amount > 0)
                <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: #d4edda;">
                    <span>
                        @if($order->discount_type === 'senior') Senior Discount
                        @elseif($order->discount_type === 'pwd') PWD Discount
                        @else Discount @endif
                    </span>
                    <span>-₱{{ number_format($order->discount_amount, 2) }}</span>
                </div>
                @endif
                <div style="display: flex; justify-content: space-between; font-size: 1rem; font-weight: 700; margin-top: 0.25rem;">
                    <span>Total:</span>
                    <span>₱{{ number_format($order->total, 2) }}</span>
                </div>
            </div>

            {{-- Primary actions row --}}
            <div style="display:flex;gap:0.4rem;margin-bottom:0.3rem;">
                @if($order->status === 'pending')
                <form action="{{ route('admin.orders.prepare', $order->id) }}" method="POST" style="flex:1;margin:0;">
                    @csrf @method('PUT')
                    <button type="submit" class="order-action-btn" style="background:#E67E22;">
                        <i class="bi bi-fire"></i> Prepare
                    </button>
                </form>
                @endif
                @if($order->status === 'preparing')
                <form action="{{ route('admin.orders.serve', $order->id) }}" method="POST" style="flex:1;margin:0;">
                    @csrf @method('PUT')
                    <button type="submit" class="order-action-btn" style="background:#27AE60;">
                        <i class="bi bi-cup-hot"></i> Serving
                    </button>
                </form>
                @endif
                @if(in_array($order->status, ['pending', 'preparing', 'serving']))
                <form action="{{ route('admin.orders.complete', $order->id) }}" method="POST" style="flex:1;margin:0;" onsubmit="return confirm('Complete this order? Inventory will auto-deduct.')">
                    @csrf @method('PUT')
                    <button type="submit" class="order-action-btn" style="background:#4CAF50;">
                        <i class="bi bi-check-circle"></i> Complete
                    </button>
                </form>
                @endif
                <div style="flex:1;">
                    <button type="button"
                        onclick="openDiscountModal(this)"
                        data-order-id="{{ $order->id }}"
                        data-order-number="{{ $order->order_number }}"
                        data-order-type="{{ $order->type }}"
                        data-discount-type="{{ $order->discount_type ?? '' }}"
                        data-discount-amount="{{ $order->discount_amount }}"
                        data-items="{{ json_encode($order->items->map(fn($it) => ['id' => $it->id, 'name' => $it->item_name, 'price' => (float) $it->item_price, 'qty' => (int) $it->quantity])) }}"
                        data-customer="{{ json_encode($discountCustomer) }}"
                        class="order-action-btn" style="background:#8E44AD;">
                        <i class="bi bi-percent"></i> Discount
                    </button>
                </div>
            </div>
            {{-- Cancel row --}}
            <form action="{{ route('admin.orders.cancel', $order->id) }}" method="POST" style="margin:0;" onsubmit="return confirm('Cancel this order?')">
                @csrf @method('PUT')
                <button type="submit" class="order-action-btn" style="background:rgba(192,57,43,0.75);padding:0.35rem 0.5rem;font-size:0.68rem;">
                    <i class="bi bi-x-circle"></i> Cancel Order
                </button>
            </form>

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

{{-- ══════════ SENIOR / PWD DISCOUNT MODAL ══════════ --}}
<div id="discountModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1200;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:white;border-radius:12px;padding:1.25rem;max-width:440px;width:100%;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
            <h3 style="font-size:1rem;font-weight:700;color:#333;margin:0;">
                <i class="bi bi-percent" style="color:#8E44AD;"></i> Senior / PWD Discount
            </h3>
            <button type="button" onclick="closeDiscountModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#888;">&times;</button>
        </div>

        <p id="dmOrderLabel" style="font-size:0.78rem;color:#888;margin-bottom:0.6rem;"></p>

        {{-- Voucher conflict --}}
        <div id="dmVoucherWarn" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:0.6rem 0.75rem;font-size:0.75rem;color:#856404;margin-bottom:0.6rem;">
            <i class="bi bi-exclamation-triangle"></i>
            This order already has a voucher discount. Remove the voucher discount first before applying Senior/PWD discount.
        </div>

        {{-- Dine-in guest note --}}
        <div id="dmDineInNote" style="display:none;background:#e8f0fe;border-radius:8px;padding:0.5rem 0.75rem;font-size:0.72rem;color:#1A56DB;margin-bottom:0.6rem;">
            <i class="bi bi-info-circle"></i> For dine-in guests, verify the physical Senior/PWD card before applying the discount.
        </div>

        {{-- Pick-up customer's saved Senior/PWD details (read-only) --}}
        <div id="dmCustomerInfo" style="display:none;background:#f5f5f5;border-radius:8px;padding:0.6rem 0.75rem;font-size:0.72rem;color:#555;line-height:1.5;margin-bottom:0.6rem;"></div>

        {{-- Apply form --}}
        <form id="dmApplyForm" method="POST">
            @csrf
            @method('PUT')
            <p style="font-size:0.78rem;font-weight:600;color:#333;margin:0 0 0.35rem;">Discount Type</p>
            <div style="display:flex;gap:0.5rem;margin-bottom:0.7rem;">
                <label style="flex:1;display:flex;align-items:center;gap:0.35rem;border:1px solid #ddd;border-radius:8px;padding:0.4rem 0.6rem;font-size:0.78rem;cursor:pointer;">
                    <input type="radio" name="discount_type" value="senior" checked> Senior Citizen
                </label>
                <label style="flex:1;display:flex;align-items:center;gap:0.35rem;border:1px solid #ddd;border-radius:8px;padding:0.4rem 0.6rem;font-size:0.78rem;cursor:pointer;">
                    <input type="radio" name="discount_type" value="pwd"> PWD
                </label>
            </div>

            <p style="font-size:0.78rem;font-weight:600;color:#333;margin:0 0 0.35rem;">Select the eligible person's item (20% off that item only)</p>
            <div id="dmItemList" style="margin-bottom:0.6rem;"></div>

            <div id="dmPreview" style="background:#f0f9f0;border-radius:8px;padding:0.5rem 0.75rem;font-size:0.78rem;color:#2E7D32;font-weight:600;margin-bottom:0.7rem;"></div>

            <button type="submit" class="btn-primary-custom" style="width:100%;padding:0.6rem;">
                <i class="bi bi-check-circle"></i> Apply Discount
            </button>
        </form>

        {{-- Remove form --}}
        <form id="dmRemoveForm" method="POST" style="display:none;margin-top:0.5rem;"
            onsubmit="return confirm('Remove the Senior/PWD discount from this order?')">
            @csrf
            @method('PUT')
            <button type="submit" class="btn-danger-custom" style="width:100%;padding:0.55rem;">
                <i class="bi bi-x-circle"></i> Remove Current Senior/PWD Discount
            </button>
        </form>
    </div>
</div>

@push('styles')
<style>
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.5} }
    .order-action-btn {
        color: white;
        border: none;
        border-radius: 6px;
        padding: 0.5rem 0.4rem;
        font-size: 0.72rem;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        font-family: 'Poppins', sans-serif;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
        white-space: nowrap;
    }
</style>
@endpush

@push('scripts')
<script>
    var autoReloadTimer = setTimeout(function() {
        location.reload();
    }, 20000);

    // Cancel auto-reload when any order action form is submitted
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            clearTimeout(autoReloadTimer);
        });
    });

    // ── Senior / PWD discount modal ──
    var dmItems = [];

    function dmIdImg(path) {
        if (!path) return '#';
        return /^https?:\//.test(path) ? path : '{{ asset('') }}' + path.replace(/^\/+/, '');
    }

    function dmEscape(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function openDiscountModal(btn) {
        clearTimeout(autoReloadTimer); // don't reload while the modal is open

        var orderId  = btn.dataset.orderId;
        var orderNo  = btn.dataset.orderNumber;
        var orderType = btn.dataset.orderType;
        var discType = btn.dataset.discountType || '';
        var discAmt  = parseFloat(btn.dataset.discountAmount || '0');
        dmItems      = JSON.parse(btn.dataset.items || '[]');
        var customer = JSON.parse(btn.dataset.customer || 'null');

        document.getElementById('dmOrderLabel').textContent = 'Order #' + orderNo;

        var applyForm  = document.getElementById('dmApplyForm');
        var removeForm = document.getElementById('dmRemoveForm');
        applyForm.action  = '/admin/orders/' + orderId + '/apply-discount';
        removeForm.action = '/admin/orders/' + orderId + '/remove-discount';

        // A voucher discount = discount amount present but not a senior/pwd type.
        var hasVoucher = discAmt > 0 && discType !== 'senior' && discType !== 'pwd';
        document.getElementById('dmVoucherWarn').style.display = hasVoucher ? 'block' : 'none';
        applyForm.style.display = hasVoucher ? 'none' : 'block';

        // Existing Senior/PWD discount → offer Remove.
        removeForm.style.display = (discType === 'senior' || discType === 'pwd') ? 'block' : 'none';

        // Dine-in guest reminder.
        document.getElementById('dmDineInNote').style.display = (orderType === 'dine_in') ? 'block' : 'none';

        // Pre-select the discount type if one is already applied.
        applyForm.querySelector('input[value="' + (discType === 'pwd' ? 'pwd' : 'senior') + '"]').checked = true;

        // Pick-up customer's saved Senior/PWD info.
        var infoBox = document.getElementById('dmCustomerInfo');
        if (customer && (customer.senior_name || customer.senior_card || customer.pwd_name || customer.pwd_card)) {
            var html = '<strong>Customer\'s saved Senior/PWD details:</strong>';
            if (customer.senior_name || customer.senior_card) {
                html += '<br>Senior: ' + dmEscape(customer.senior_name || '—') + ' / ' + dmEscape(customer.senior_card || 'no card #');
                if (customer.senior_image) html += ' <a href="' + dmIdImg(customer.senior_image) + '" target="_blank">view ID</a>';
            }
            if (customer.pwd_name || customer.pwd_card) {
                html += '<br>PWD: ' + dmEscape(customer.pwd_name || '—') + ' / ' + dmEscape(customer.pwd_card || 'no card #');
                if (customer.pwd_image) html += ' <a href="' + dmIdImg(customer.pwd_image) + '" target="_blank">view ID</a>';
            }
            infoBox.innerHTML = html;
            infoBox.style.display = 'block';
        } else {
            infoBox.style.display = 'none';
        }

        if (!hasVoucher) {
            buildDiscountItemList();
        }

        document.getElementById('discountModal').style.display = 'flex';
    }

    function buildDiscountItemList() {
        var list = document.getElementById('dmItemList');
        list.innerHTML = '';
        if (dmItems.length === 0) {
            list.innerHTML = '<p style="font-size:0.75rem;color:#888;">No items on this order.</p>';
            document.getElementById('dmPreview').textContent = '';
            return;
        }
        // Recommend the most expensive line: max(price * qty).
        var topIdx = 0, topVal = -1;
        dmItems.forEach(function (it, i) {
            var line = it.price * it.qty;
            if (line > topVal) { topVal = line; topIdx = i; }
        });
        dmItems.forEach(function (it, i) {
            var line = it.price * it.qty;
            var label = document.createElement('label');
            label.style.cssText = 'display:flex;align-items:center;gap:0.5rem;border:1px solid #ddd;border-radius:8px;padding:0.45rem 0.6rem;margin-bottom:0.35rem;font-size:0.78rem;cursor:pointer;';
            label.innerHTML =
                '<input type="radio" name="order_item_id" value="' + it.id + '" ' +
                (i === topIdx ? 'checked' : '') + ' onchange="updateDiscountPreview()">' +
                '<span style="flex:1;">' + dmEscape(it.name) + ' &times;' + it.qty +
                ' <span style="color:#888;">(₱' + line.toFixed(2) + ')</span></span>' +
                (i === topIdx ? '<span style="background:#8E44AD;color:white;border-radius:10px;padding:0.05rem 0.45rem;font-size:0.62rem;">Recommended</span>' : '');
            list.appendChild(label);
        });
        updateDiscountPreview();
    }

    function updateDiscountPreview() {
        var sel = document.querySelector('#dmItemList input[name="order_item_id"]:checked');
        var preview = document.getElementById('dmPreview');
        if (!sel) { preview.textContent = ''; return; }
        var item = dmItems.find(function (it) { return String(it.id) === String(sel.value); });
        if (!item) { preview.textContent = ''; return; }
        var discount = item.price * item.qty * 0.20;
        preview.textContent = 'Discount: 20% of ' + item.name + ' = -₱' + discount.toFixed(2);
    }

    function closeDiscountModal() {
        document.getElementById('discountModal').style.display = 'none';
    }
</script>
@endpush
@endsection
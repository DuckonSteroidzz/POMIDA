@extends('admin.layout')

@section('title', 'Completed Orders - Peachy Admin')

@section('content')

{{-- ── Toolbar: title + print actions ── --}}
<div class="co-toolbar no-print" style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem;">
    <p class="page-title" style="margin:0;">Completed Orders</p>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
        <span id="selectedCount" style="font-size:0.75rem;color:#888;font-weight:600;">0 selected</span>
        <button type="button" onclick="printSelected()" class="btn-primary-custom" style="padding:0.5rem 0.9rem;font-size:0.75rem;">
            <i class="bi bi-printer"></i> Print Selected
        </button>
        <button type="button" onclick="printFiltered()" class="btn-primary-custom" style="padding:0.5rem 0.9rem;font-size:0.75rem;">
            <i class="bi bi-printer-fill"></i> Print Filtered Results
        </button>
    </div>
</div>

{{-- ── Print-only Report Header ── --}}
<div class="print-only print-header">
    <h2 style="margin:0 0 0.25rem;">Peachy Cakes &amp; Deli Cafe</h2>
    <p style="margin:0;font-size:0.95rem;font-weight:600;">Completed Orders Report</p>
    <p style="margin:0;font-size:0.85rem;">Branch: {{ $selectedBranchName ?? 'All Branches' }}</p>
    <p style="margin:0;font-size:0.85rem;">
        Date Range:
        @if(request('date_from') || request('date_to'))
            {{ request('date_from') ?: '—' }} to {{ request('date_to') ?: '—' }}
        @else
            All Available Data
        @endif
        @if(request('type'))
            &nbsp;&bull;&nbsp; Type: {{ ucfirst(str_replace('_', ' ', request('type'))) }}
        @endif
        @if(request('status'))
            &nbsp;&bull;&nbsp; Status: {{ ucfirst(request('status')) }}
        @endif
    </p>
    <p style="margin:0;font-size:0.8rem;color:#555;">Generated: {{ now()->format('M d, Y g:i A') }}</p>
    <p id="printScopeNote" style="margin:0;font-size:0.8rem;color:#555;"></p>
    <hr>
</div>

{{-- ── Filter ── --}}
<div class="content-card no-print" style="margin-bottom:1rem;">
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
                <th class="no-print" style="padding:0.6rem;text-align:center;width:34px;">
                    <input type="checkbox" id="selectAll" onclick="toggleAll(this)" style="cursor:pointer;">
                </th>
                <th style="padding:0.6rem;text-align:left;">Date</th>
                <th style="padding:0.6rem;text-align:left;">Order #</th>
                <th style="padding:0.6rem;text-align:center;">Type</th>
                <th style="padding:0.6rem;text-align:left;">Items</th>
                <th style="padding:0.6rem;text-align:right;">Subtotal</th>
                <th style="padding:0.6rem;text-align:right;">Discount</th>
                <th style="padding:0.6rem;text-align:right;">Total</th>
                <th style="padding:0.6rem;text-align:center;">Payment</th>
                <th style="padding:0.6rem;text-align:center;">Status</th>
                <th class="no-print" style="padding:0.6rem;text-align:center;">Print</th>
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
                    <td class="no-print" style="padding:0.6rem;text-align:center;">
                        <input type="checkbox" class="row-check" onchange="updateCount()" style="cursor:pointer;">
                    </td>
                    <td style="padding:0.6rem;font-size:0.75rem;">{{ ($order->completed_at ?? $order->created_at)->format('M d, Y h:i A') }}</td>
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
                        @if($order->discount_amount > 0)
                            -₱{{ number_format($order->discount_amount, 2) }}
                            @if(in_array($order->discount_type, ['senior', 'pwd']))
                                <br><span style="font-size:0.62rem;color:#888;">{{ $order->discount_type === 'senior' ? 'Senior' : 'PWD' }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td style="padding:0.6rem;text-align:right;font-weight:700;">₱{{ number_format($order->total, 2) }}</td>
                    <td style="padding:0.6rem;text-align:center;">{{ ucfirst($order->payment_method ?? 'cash') }}</td>
                    <td style="padding:0.6rem;text-align:center;">
                        <span style="background:{{ $statusColor }};color:white;padding:0.15rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:600;">
                            {{ $statusLabel }}
                        </span>
                    </td>
                    <td class="no-print" style="padding:0.6rem;text-align:center;">
                        <button onclick="printReceipt({{ $order->id }})" style="background:#F4845F;color:white;padding:0.2rem 0.6rem;border-radius:6px;font-size:0.7rem;font-weight:600;border:none;cursor:pointer;">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </td>
                </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="11" style="text-align:center;color:#aaa;padding:2rem;">No orders found</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

@endsection

@push('styles')
<style>
    /* Hidden on screen, shown only when printing */
    .print-only { display: none !important; }

    @media print {
        /* Hide all admin chrome */
        .sidebar,
        .sidebar-overlay,
        .hamburger-btn,
        .no-print {
            display: none !important;
        }

        /* Hide the admin branch-selector bar rendered by the layout */
        .main-content > div[style*="justify-content:space-between"] {
            display: none !important;
        }

        body {
            background: white !important;
            display: block !important;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 0.5rem !important;
        }

        /* Reveal the report header */
        .print-only { display: block !important; }
        .print-header h2 { font-size: 1.3rem; font-weight: 700; }

        /* Rows excluded from a "Print Selected" run */
        .print-hide-row { display: none !important; }

        .content-card {
            box-shadow: none !important;
            border: none !important;
            padding: 0 !important;
            margin-bottom: 0 !important;
            overflow: visible !important;
        }

        table { font-size: 0.7rem !important; }
        table th, table td { padding: 0.3rem !important; }

        a[href]::after { content: ''; }
    }
</style>
@endpush

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

function toggleAll(master) {
    document.querySelectorAll('.row-check').forEach(function(c) {
        c.checked = master.checked;
    });
    updateCount();
}

function updateCount() {
    var checks = document.querySelectorAll('.row-check');
    var selected = document.querySelectorAll('.row-check:checked').length;
    document.getElementById('selectedCount').innerText = selected + ' selected';
    var master = document.getElementById('selectAll');
    if (master) {
        master.checked = (checks.length > 0 && selected === checks.length);
    }
}

function printFiltered() {
    // Print every order currently listed (already filtered server-side).
    document.querySelectorAll('tr.print-hide-row').forEach(function(r) {
        r.classList.remove('print-hide-row');
    });
    document.getElementById('printScopeNote').innerText = 'Scope: All filtered results currently listed';
    window.print();
}

function printSelected() {
    var checks = document.querySelectorAll('.row-check');
    var anySelected = false;

    checks.forEach(function(c) {
        var row = c.closest('tr');
        if (c.checked) {
            anySelected = true;
            row.classList.remove('print-hide-row');
        } else {
            row.classList.add('print-hide-row');
        }
    });

    if (!anySelected) {
        // Restore so no rows stay flagged.
        document.querySelectorAll('tr.print-hide-row').forEach(function(r) {
            r.classList.remove('print-hide-row');
        });
        alert('Please select at least one order to print.');
        return;
    }

    document.getElementById('printScopeNote').innerText = 'Scope: Selected orders only';
    window.print();
}

// Restore the table to its normal state after the print dialog closes.
window.addEventListener('afterprint', function() {
    document.querySelectorAll('tr.print-hide-row').forEach(function(r) {
        r.classList.remove('print-hide-row');
    });
});
</script>
@endpush

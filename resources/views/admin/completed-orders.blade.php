@extends('admin.layout')

@section('title', 'Completed Orders - Peachy Admin')

@section('content')

{{-- ── Header: title + print actions ── --}}
<div class="co-header no-print">
    <div class="co-header-text">
        <p class="co-title">Order History</p>
        <p class="co-sub">Completed &amp; cancelled orders{{ isset($selectedBranchName) && $selectedBranchName ? ' · '.$selectedBranchName : '' }}</p>
    </div>
    <div class="co-actions">
        <span id="selectedCount" class="co-chip">0 selected</span>
        <button type="button" onclick="printSelected()" class="co-btn co-btn-ghost">
            <i class="bi bi-printer"></i> Print Selected
        </button>
        <button type="button" onclick="printFiltered()" class="co-btn co-btn-solid">
            <i class="bi bi-printer-fill"></i> Print Filtered
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
<div class="co-card co-filter-card no-print">
    <form method="GET" action="{{ route('admin.completed-orders') }}" class="co-filter">
        <div class="co-field">
            <label class="co-label" for="co-date-from">Date From</label>
            <input id="co-date-from" type="date" name="date_from" class="co-input" value="{{ request('date_from') }}">
        </div>
        <div class="co-field">
            <label class="co-label" for="co-date-to">Date To</label>
            <input id="co-date-to" type="date" name="date_to" class="co-input" value="{{ request('date_to') }}">
        </div>
        <div class="co-field">
            <label class="co-label" for="co-type">Type</label>
            <select id="co-type" name="type" class="co-input">
                <option value="">All Types</option>
                <option value="dine_in" {{ request('type') == 'dine_in' ? 'selected' : '' }}>Dine In</option>
                <option value="pick_up" {{ request('type') == 'pick_up' ? 'selected' : '' }}>Pick Up</option>
            </select>
        </div>
        <div class="co-field">
            <label class="co-label" for="co-status">Status</label>
            <select id="co-status" name="status" class="co-input">
                <option value="">All</option>
                <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
            </select>
        </div>
        <div class="co-field co-field-actions">
            <button type="submit" class="co-btn co-btn-solid">
                <i class="bi bi-funnel"></i> Apply Filters
            </button>
            <a href="{{ route('admin.completed-orders') }}" class="co-btn co-btn-ghost">Reset</a>
        </div>
    </form>
</div>

{{-- The on-screen reminder that used to sit here ("Completed orders are kept
     on purpose...") was removed on request — the owner found it cluttered the
     page. The POLICY it described is UNCHANGED: there is still no delete
     button on a completed order (the table below has no delete action, on
     purpose, and none has been added), and Cancel while an order is still
     open remains the only correction path, marking it cancelled and excluding
     it from revenue and analytics. Only the paragraph explaining this is
     gone. --}}

<div class="co-card co-table-card">
    <table class="co-table">
        <thead>
            <tr>
                <th class="no-print co-col-check">
                    <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                </th>
                <th class="co-ta-left">Date</th>
                <th class="co-ta-left">Order #</th>
                <th class="co-ta-center">Type</th>
                <th class="co-ta-left">Items</th>
                <th class="co-ta-right">Subtotal</th>
                <th class="co-ta-right">Discount</th>
                <th class="co-ta-right">Total</th>
                <th class="co-ta-center">Payment</th>
                <th class="co-ta-center">Status</th>
                <th class="co-ta-center">Rating</th>
                <th class="no-print co-ta-center">Print</th>
            </tr>
        </thead>
        <tbody id="coBody">
            @if(count($orders) > 0)
                @foreach($orders as $order)
                @php
                    $statusLabel = ucfirst($order->status);
                    $statusClass = $order->status === 'completed' ? 'co-badge-ok' : 'co-badge-bad';
                    $typeLabel = match($order->type) {
                        'dine_in' => 'Dine In',
                        'pick_up' => 'Pickup',
                        'walk_in' => 'Walk-in',
                        default => $order->type,
                    };
                    $orderRating = $orderRatings[$order->id] ?? null;
                @endphp
                <tr class="co-row">
                    <td class="no-print co-col-check" data-label="">
                        <input type="checkbox" class="row-check" onchange="updateCount()">
                    </td>
                    <td data-label="Date" class="co-date">{{ ($order->completed_at ?? $order->created_at)->format('M d, Y h:i A') }}</td>
                    <td data-label="Order #" class="co-order-no">{{ $order->order_number }}</td>
                    <td data-label="Type" class="co-ta-center">
                        <span class="co-badge co-badge-type">{{ $typeLabel }}</span>
                    </td>
                    <td data-label="Items" class="co-items">
                        @foreach($order->items as $item)
                            {{ $item->quantity }}x {{ $item->item_name }}@if(!$loop->last), @endif
                        @endforeach
                    </td>
                    <td data-label="Subtotal" class="co-ta-right co-num">₱{{ number_format($order->subtotal, 2) }}</td>
                    <td data-label="Discount" class="co-ta-right co-num co-discount">
                        @if($order->discount_amount > 0)
                            -₱{{ number_format($order->discount_amount, 2) }}
                            @if($order->discountLabel() !== '' && $order->discountLabel() !== 'Discount')
                                <span class="co-discount-tag">{{ $order->discountLabel() }}</span>
                            @endif
                        @else
                            <span class="co-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Total" class="co-ta-right co-num co-total">₱{{ number_format($order->total, 2) }}</td>
                    {{-- Method AND settlement state. The method alone did not say
                         whether the money had actually been taken, which is the same
                         gap the Active Orders board had for Cash orders. --}}
                    <td data-label="Payment" class="co-ta-center co-pay">
                        @php
                            $coMethod = strtolower((string) ($order->payment_method ?? 'cash'));
                            $coStatus = strtolower((string) ($order->payment_status ?? ''));
                            $coLabel = match ($coMethod) {
                                'gcash' => 'GCash',
                                'cash'  => 'Cash',
                                default => ucfirst($coMethod ?: 'Cash'),
                            };
                        @endphp
                        {{ $coLabel }}
                        <span class="co-muted" style="display:block; font-size:0.7rem;">
                            {{ $coStatus === 'paid' ? 'Paid' : ucfirst(str_replace('_', ' ', $coStatus ?: 'unpaid')) }}
                        </span>
                    </td>
                    <td data-label="Status" class="co-ta-center">
                        <span class="co-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                    </td>
                    <td data-label="Rating" class="co-ta-center co-rating">
                        @if($orderRating)
                            <span title="{{ $orderRating->rating }}/5">
                                {{ str_repeat('★', $orderRating->rating) }}{{ str_repeat('☆', 5 - $orderRating->rating) }}
                            </span>
                            <span class="co-muted">({{ $orderRating->rating }}/5)</span>
                        @else
                            <span class="co-muted">Not rated yet</span>
                        @endif
                    </td>
                    <td class="no-print co-ta-center" data-label="">
                        <button type="button" onclick="printReceipt({{ $order->id }})" class="co-print-btn">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </td>
                </tr>
                @endforeach
            @else
                <tr class="co-empty-row">
                    <td colspan="12">
                        <div class="co-empty">
                            <i class="bi bi-receipt"></i>
                            <p>No orders found</p>
                            <span>Try widening your date range or clearing the filters.</span>
                        </div>
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    {{-- ── Client-side pagination (display only; all rows stay printable) ── --}}
    <div class="co-pager no-print" id="coPager" hidden>
        <div class="co-pager-info">
            <span id="coPagerRange"></span>
            <label class="co-pager-size">
                Rows
                <select id="coPageSize" class="co-input co-input-sm">
                    <option value="15">15</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </label>
        </div>
        <div class="co-pager-nav">
            <button type="button" class="co-page-btn" id="coPrev" onclick="coGo(coPage - 1)">
                <i class="bi bi-chevron-left"></i>
            </button>
            <span class="co-page-list" id="coPageList"></span>
            <button type="button" class="co-page-btn" id="coNext" onclick="coGo(coPage + 1)">
                <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>
</div>

@endsection

@push('styles')
<link href="/vendor/gfonts.css" rel="stylesheet">
<style>
    /* ── Peachy tokens ── */
    .co-header, .co-card, .co-pager, .co-table {
        --peach-100: #FDF6EF;
        --peach-200: #F8D7B0;
        --peach-300: #F6B49B;
        --peach-400: #F4845F;
        --peach-500: #EF8585;
        --peach-700: #C0392B;
        --ink: #3B2A24;
        --ink-soft: #8B7A72;
        --line: #F0E2D5;
    }

    .co-header, .co-card, .co-pager { font-family: 'Karla', system-ui, sans-serif; color: #3B2A24; }

    /* ── Header ── */
    .co-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.85rem;
        margin-bottom: 1.1rem;
    }
    .co-header-text { min-width: 0; }
    .co-title {
        font-family: 'Fraunces', Georgia, serif;
        font-weight: 700;
        font-size: 1.75rem;
        letter-spacing: -0.01em;
        margin: 0;
        color: #3B2A24;
    }
    .co-sub { margin: 0.15rem 0 0; font-size: 0.82rem; color: #8B7A72; }
    .co-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
    .co-chip {
        font-size: 0.72rem; font-weight: 700; letter-spacing: 0.02em;
        color: #C0392B; background: #FDF1E6;
        border: 1px solid #F8D7B0; border-radius: 999px;
        padding: 0.35rem 0.7rem;
    }

    /* ── Buttons ── */
    .co-btn {
        display: inline-flex; align-items: center; gap: 0.4rem;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 0.78rem; font-weight: 700;
        padding: 0.55rem 1rem; border-radius: 10px; border: 1px solid transparent;
        cursor: pointer; text-decoration: none; line-height: 1.1;
        transition: transform .12s ease, box-shadow .12s ease, background .12s ease;
    }
    .co-btn:hover { transform: translateY(-1px); }
    .co-btn-solid {
        background: linear-gradient(135deg, #F4845F, #EF8585);
        color: #fff; box-shadow: 0 6px 14px -8px rgba(192,57,43,.6);
    }
    .co-btn-solid:hover { background: linear-gradient(135deg, #EF8585, #C0392B); color: #fff; }
    .co-btn-ghost { background: #fff; color: #C0392B; border-color: #F6B49B; }
    .co-btn-ghost:hover { background: #FDF6EF; color: #C0392B; }

    /* ── Cards ── */
    .co-card {
        background: #fff;
        border: 1px solid #F0E2D5;
        border-radius: 16px;
        box-shadow: 0 10px 30px -24px rgba(59,42,36,.45);
        margin-bottom: 1rem;
    }
    .co-filter-card { padding: 1rem 1.1rem; background: linear-gradient(180deg, #FDF6EF, #fff); }
    .co-table-card { padding: 0; overflow: hidden; }

    /* ── Filter ── */
    .co-filter {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0.75rem;
        align-items: end;
    }
    .co-field { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; }
    .co-field-actions { flex-direction: row; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .co-label {
        font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.08em; color: #8B7A72;
    }
    .co-input {
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 0.82rem; color: #3B2A24;
        background: #fff; border: 1px solid #F0E2D5; border-radius: 10px;
        padding: 0.5rem 0.6rem; width: 100%; margin: 0;
    }
    .co-input:focus { outline: none; border-color: #F4845F; box-shadow: 0 0 0 3px rgba(244,132,95,.18); }
    .co-input-sm { padding: 0.25rem 0.4rem; font-size: 0.75rem; width: auto; }

    /* ── Table ── */
    .co-table-card { overflow-x: auto; }
    .co-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.82rem; }
    .co-table thead th {
        background: #FDF1E6;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.07em; color: #C0392B;
        padding: 0.75rem 0.7rem; white-space: nowrap;
        border-bottom: 1px solid #F0E2D5;
        position: sticky; top: 0; z-index: 1;
    }
    .co-table tbody td { padding: 0.7rem; border-bottom: 1px solid #F7EFE7; vertical-align: middle; }
    .co-table tbody tr:hover td { background: #FFFBF7; }
    .co-ta-left { text-align: left; }
    .co-ta-center { text-align: center; }
    .co-ta-right { text-align: right; }
    .co-col-check { width: 38px; text-align: center; }
    .co-col-check input, .row-check, #selectAll { cursor: pointer; accent-color: #F4845F; }
    .co-date { font-size: 0.75rem; color: #8B7A72; white-space: nowrap; }
    .co-order-no { font-family: 'Fraunces', Georgia, serif; font-weight: 600; color: #C0392B; white-space: nowrap; }
    .co-items { font-size: 0.75rem; color: #5B4740; max-width: 260px; }
    .co-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .co-total { font-weight: 700; color: #3B2A24; }
    .co-discount { color: #2E7D5B; }
    .co-discount-tag { display: block; font-size: 0.62rem; color: #8B7A72; font-weight: 600; }
    .co-muted { color: #C4B6AE; }
    .co-pay { font-size: 0.76rem; color: #5B4740; }

    .co-badge {
        display: inline-block; font-size: 0.68rem; font-weight: 700;
        padding: 0.2rem 0.6rem; border-radius: 999px; white-space: nowrap;
    }
    .co-badge-type { background: #F8D7B0; color: #8A4A2E; }
    .co-badge-ok { background: #E6F4EC; color: #2E7D5B; border: 1px solid #C6E6D5; }
    .co-badge-bad { background: #FBE7E4; color: #C0392B; border: 1px solid #F6C9C1; }

    .co-print-btn {
        display: inline-flex; align-items: center; gap: 0.3rem;
        background: #fff; color: #C0392B; border: 1px solid #F6B49B;
        padding: 0.3rem 0.65rem; border-radius: 8px;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 0.7rem; font-weight: 700; cursor: pointer;
    }
    .co-print-btn:hover { background: linear-gradient(135deg, #F4845F, #EF8585); color: #fff; border-color: transparent; }

    .co-empty { text-align: center; padding: 2.5rem 1rem; color: #8B7A72; }
    .co-empty i { font-size: 1.6rem; color: #F6B49B; }
    .co-empty p { font-family: 'Fraunces', Georgia, serif; font-size: 1rem; font-weight: 600; margin: 0.5rem 0 0.2rem; color: #3B2A24; }
    .co-empty span { font-size: 0.78rem; }

    /* ── Pagination ── */
    .co-pager {
        display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.7rem;
        padding: 0.8rem 1rem; border-top: 1px solid #F0E2D5; background: #FFFBF7;
    }
    .co-pager-info {
        display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
        font-size: 0.76rem; color: #8B7A72; font-weight: 600;
    }
    .co-pager-size { display: inline-flex; align-items: center; gap: 0.35rem; }
    .co-pager-nav { display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; }
    .co-page-list { display: flex; align-items: center; gap: 0.25rem; flex-wrap: wrap; }
    .co-page-btn {
        min-width: 32px; height: 32px; padding: 0 0.5rem;
        background: #fff; border: 1px solid #F0E2D5; border-radius: 9px;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 0.76rem; font-weight: 700; color: #5B4740;
        cursor: pointer;
    }
    .co-page-btn:hover:not(:disabled) { border-color: #F4845F; color: #C0392B; }
    .co-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .co-page-btn.is-active {
        background: linear-gradient(135deg, #F4845F, #EF8585);
        color: #fff; border-color: transparent;
    }
    .co-page-gap { color: #C4B6AE; padding: 0 0.15rem; }

    /* Rows outside the current page: hidden on screen, still printable */
    .co-page-hidden { display: none; }

    /* ── Desktop ── */
    @media (min-width: 768px) {
        .co-header {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
        }
        .co-actions { justify-content: flex-end; }
        .co-pager { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
        .co-pager-nav { justify-content: flex-end; }
    }

    /* ── Mobile: table rows become cards ── */
    @media (max-width: 767px) {
        .co-title { font-size: 1.4rem; }
        .co-table-card { border: none; background: transparent; box-shadow: none; overflow: visible; }
        .co-table thead { display: none; }
        .co-table, .co-table tbody, .co-table tr, .co-table td { display: block; width: 100%; }
        .co-table tbody tr.co-row {
            background: #fff; border: 1px solid #F0E2D5; border-radius: 14px;
            box-shadow: 0 10px 26px -24px rgba(59,42,36,.5);
            padding: 0.35rem 0.15rem; margin-bottom: 0.7rem;
            position: relative;
        }
        .co-table tbody tr.co-row:hover td { background: transparent; }
        .co-table tbody td {
            display: grid; grid-template-columns: 40% minmax(0, 60%);
            gap: 0.5rem; align-items: center;
            border-bottom: 1px dashed #F7EFE7;
            padding: 0.5rem 0.85rem; text-align: left !important;
        }
        .co-table tbody td:last-child { border-bottom: none; }
        .co-table tbody td::before {
            content: attr(data-label);
            font-size: 0.66rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.07em; color: #8B7A72;
        }
        .co-table tbody td[data-label=""] { grid-template-columns: 1fr; }
        .co-table tbody td[data-label=""]::before { content: none; }
        .co-table tbody td.co-col-check { padding-bottom: 0.25rem; border-bottom: none; }
        .co-items { max-width: none; }
        .co-pager {
            background: #fff; border: 1px solid #F0E2D5; border-radius: 14px;
        }
        .co-empty-row td { padding: 0; }
    }

    /* Hidden on screen, shown only when printing */
    .print-only { display: none !important; }

    @media print {
        /* Hide all admin chrome: sidebar, the mobile burger/topbar and its
           overlay, and everything flagged .no-print (the on-screen header with
           its Print buttons, the filter card, the row checkboxes, the pager). */
        .sidebar,
        .sidebar-overlay,
        .hamburger-btn,
        .pc-topbar,
        .pc-burger,
        .pc-overlay,
        .no-print {
            display: none !important;
        }

        /* Hide the layout's top line — the branch selector AND the
           notification bell both live in .pc-topline. The printed report has
           its own header (branch, range, generated-at) below. */
        .pc-topline {
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

        /* Paginated-away rows must still print */
        .co-page-hidden { display: table-row !important; }

        /* Rows excluded from a "Print Selected" run */
        .print-hide-row { display: none !important; }

        .content-card,
        .co-card {
            box-shadow: none !important;
            border: none !important;
            padding: 0 !important;
            margin-bottom: 0 !important;
            overflow: visible !important;
            background: #fff !important;
            border-radius: 0 !important;
        }

        table { font-size: 0.7rem !important; width: 100% !important; }
        table th, table td { padding: 0.3rem !important; }
        .co-table thead th { position: static !important; background: #fff !important; color: #000 !important; }
        .co-table tbody td::before { content: none !important; }

        /* The column header repeats at the top of every page the table spans,
           and no row is ever split across a page break. Same treatment as the
           Summary report's printed tables. */
        .co-table thead { display: table-header-group; }
        .co-table tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* Money columns keep lining figures so they align on paper; the Total
           column stays bold so it stands out. */
        .co-num { font-variant-numeric: tabular-nums; }
        .co-total { font-weight: 700 !important; }

        /* Status / type badges read as plain words on paper — no rounded
           outline, no fill. Screen appearance is unchanged. */
        .co-badge {
            border: none !important;
            background: transparent !important;
            color: #000 !important;
            padding: 0 !important;
            border-radius: 0 !important;
            font-weight: 600 !important;
        }

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

/* ── Display-only pagination: hides rows visually, printing still covers all ── */
var coPage = 1;
var coPageSize = 25;

function coRows() {
    return Array.prototype.slice.call(document.querySelectorAll('#coBody tr.co-row'));
}

function coRender() {
    var rows = coRows();
    var pager = document.getElementById('coPager');
    if (!pager) return;

    if (rows.length === 0) {
        pager.hidden = true;
        return;
    }

    var pages = Math.max(1, Math.ceil(rows.length / coPageSize));
    if (coPage > pages) coPage = pages;
    if (coPage < 1) coPage = 1;

    var start = (coPage - 1) * coPageSize;
    var end = Math.min(start + coPageSize, rows.length);

    rows.forEach(function(row, i) {
        row.classList.toggle('co-page-hidden', i < start || i >= end);
    });

    pager.hidden = rows.length <= 15 && pages === 1;
    document.getElementById('coPagerRange').innerText =
        'Showing ' + (start + 1) + '–' + end + ' of ' + rows.length + ' orders';

    document.getElementById('coPrev').disabled = (coPage === 1);
    document.getElementById('coNext').disabled = (coPage === pages);

    var list = document.getElementById('coPageList');
    list.innerHTML = '';
    coPageNumbers(coPage, pages).forEach(function(p) {
        if (p === '…') {
            var gap = document.createElement('span');
            gap.className = 'co-page-gap';
            gap.innerText = '…';
            list.appendChild(gap);
            return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'co-page-btn' + (p === coPage ? ' is-active' : '');
        btn.innerText = p;
        btn.onclick = function() { coGo(p); };
        list.appendChild(btn);
    });
}

function coPageNumbers(current, pages) {
    var out = [];
    for (var i = 1; i <= pages; i++) {
        if (i === 1 || i === pages || Math.abs(i - current) <= 1) {
            out.push(i);
        } else if (out[out.length - 1] !== '…') {
            out.push('…');
        }
    }
    return out;
}

function coGo(page) {
    coPage = page;
    coRender();
    var card = document.querySelector('.co-table-card');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

document.addEventListener('DOMContentLoaded', function() {
    var sizeSelect = document.getElementById('coPageSize');
    if (sizeSelect) {
        coPageSize = parseInt(sizeSelect.value, 10) || 25;
        sizeSelect.addEventListener('change', function() {
            coPageSize = parseInt(this.value, 10) || 25;
            coPage = 1;
            coRender();
        });
    }
    coRender();
    updateCount();
});
</script>
@endpush
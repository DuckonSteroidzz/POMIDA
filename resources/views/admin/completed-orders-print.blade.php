<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Completed Orders Report - Peachy Cakes &amp; Deli Cafe</title>

    {{--
        Standalone print document, deliberately NOT extending admin.layout.

        "Print Filtered" used to just reveal every row already sitting in the
        list page's DOM and call window.print() on that same page — safe only
        because the list page always held every matching row. Since the list
        is now paginated (2026-09-14), that DOM only ever holds one page, so
        printing it would silently print one page instead of the filtered
        set the button promises. This route re-runs the SAME filtered query
        with no pagination (AdminController::completedOrdersQuery()) and
        renders it here, on its own page with no sidebar/branch-bar/bell to
        hide — there is nothing on this document but the report.

        Rendered in-page: printFiltered() in completed-orders.blade.php
        fetches this route's HTML into a hidden iframe and prints from there
        (see printInFrame() in admin.layout) — same pattern printReceipt()
        now uses for a single order — so this view never navigates the
        browser or opens a tab.
    --}}
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #3B2A24;
            padding: 1.25rem;
        }
        .print-header h2 { font-family: 'Fraunces', Georgia, serif; font-size: 1.3rem; font-weight: 700; margin: 0 0 0.25rem; }
        .print-header p { margin: 0; font-size: 0.85rem; }
        .print-header hr { margin: 0.6rem 0 1rem; border: none; border-top: 1px solid #F0E2D5; }

        .co-actions-bar { display: flex; justify-content: flex-end; margin-bottom: 0.75rem; }
        .co-actions-bar button {
            font-family: inherit; font-size: 0.8rem; font-weight: 700;
            padding: 0.5rem 1rem; border-radius: 10px; border: 1px solid #F6B49B;
            background: #fff; color: #C0392B; cursor: pointer;
        }

        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.78rem; }
        thead th {
            background: #FDF1E6; font-size: 0.66rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #C0392B; text-align: left; padding: 0.55rem 0.6rem;
            border-bottom: 1px solid #F0E2D5;
        }
        tbody td { padding: 0.55rem 0.6rem; border-bottom: 1px solid #F7EFE7; vertical-align: middle; }
        .co-ta-center { text-align: center; }
        .co-ta-right { text-align: right; }
        .co-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .co-total { font-weight: 700; }
        .co-empty { text-align: center; padding: 2rem 1rem; color: #8B7A72; }

        @media print {
            .co-actions-bar { display: none !important; }
            body { padding: 0; }
            table { font-size: 0.7rem; }
            thead th { position: static; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="print-header">
        <h2>Peachy Cakes &amp; Deli Cafe</h2>
        <p style="font-weight:600;">Completed Orders Report</p>
        <p>Branch: {{ $selectedBranchName ?? 'All Branches' }}</p>
        <p>
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
        <p style="color:#555;">Generated: {{ now()->format('M d, Y g:i A') }}</p>
        <p style="color:#555;">Scope: All filtered results ({{ count($orders) }} order{{ count($orders) === 1 ? '' : 's' }})</p>
        <hr>
    </div>

    <div class="co-actions-bar">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    @if(count($orders) > 0)
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Order #</th>
                <th class="co-ta-center">Type</th>
                <th>Items</th>
                <th class="co-ta-right">Subtotal</th>
                <th class="co-ta-right">Discount</th>
                <th class="co-ta-right">Total</th>
                <th class="co-ta-center">Payment</th>
                <th class="co-ta-center">Status</th>
                <th class="co-ta-center">Rating</th>
            </tr>
        </thead>
        <tbody>
            @foreach($orders as $order)
                @php
                    $statusLabel = ucfirst($order->status);
                    $typeLabel = match($order->type) {
                        'dine_in' => 'Dine In',
                        'pick_up' => 'Pickup',
                        'walk_in' => 'Walk-in',
                        default => $order->type,
                    };
                    $orderRating = $orderRatings[$order->id] ?? null;
                    $coMethod = strtolower((string) ($order->payment_method ?? 'cash'));
                    $coStatus = strtolower((string) ($order->payment_status ?? ''));
                    $coLabel = match ($coMethod) {
                        'gcash' => 'GCash',
                        'cash'  => 'Cash',
                        default => ucfirst($coMethod ?: 'Cash'),
                    };
                @endphp
                <tr>
                    <td>{{ ($order->completed_at ?? $order->created_at)->format('M d, Y h:i A') }}</td>
                    <td>{{ $order->order_number }}</td>
                    <td class="co-ta-center">
                        @if($order->type === 'dine_in' && $order->is_takeout)Table {{ $order->table_number ?? 'N/A' }} · Take Out @else{{ $typeLabel }}@endif
                    </td>
                    <td>
                        @foreach($order->items as $item)
                            {{ $item->quantity }}x {{ $item->item_name }}@if(!$loop->last), @endif
                        @endforeach
                    </td>
                    <td class="co-ta-right co-num">₱{{ number_format($order->subtotal, 2) }}</td>
                    <td class="co-ta-right co-num">
                        @if($order->discount_amount > 0)-₱{{ number_format($order->discount_amount, 2) }}@else—@endif
                    </td>
                    <td class="co-ta-right co-num co-total">₱{{ number_format($order->total, 2) }}</td>
                    <td class="co-ta-center">{{ $coLabel }} ({{ $coStatus === 'paid' ? 'Paid' : ucfirst(str_replace('_', ' ', $coStatus ?: 'unpaid')) }})</td>
                    <td class="co-ta-center">{{ $statusLabel }}</td>
                    <td class="co-ta-center">
                        {{-- Blade's @else regex requires a non-word character right
                             before it (\B@else) — "5/5@else" fails silently and
                             leaks "@else" as literal text, since the digit "5"
                             right before it IS a word character. The space below
                             is deliberate, not stray formatting. --}}
                        @if($orderRating){{ $orderRating->rating }}/5 @else —@endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <div class="co-empty">No orders match the current filters.</div>
    @endif
</body>
</html>

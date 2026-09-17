<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Analytics Report - Peachy Cakes &amp; Deli Cafe</title>

    {{--
        Standalone print document, deliberately NOT extending admin.layout —
        same reasoning as completed-orders-print.blade.php: nothing on this
        page but the report, no sidebar/branch-bar/bell to hide.

        Rendered in-page: printAnalytics() in analytics.blade.php fetches
        this route's HTML into a hidden iframe and prints from there (see
        printInFrame() in admin.layout), so this view never navigates the
        browser and this document's own Print button below stays available
        for a manual reprint.
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

        .an-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
        .an-kpi-card { border: 1px solid #F0E2D5; border-radius: 8px; padding: 0.75rem 0.9rem; }
        .an-kpi-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #8B7A72; margin-bottom: 0.3rem; }
        .an-kpi-value { font-size: 1.15rem; font-weight: 800; color: #3B2A24; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.78rem; margin-bottom: 1.25rem; }
        thead th {
            background: #FDF1E6; font-size: 0.66rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #C0392B; text-align: left; padding: 0.55rem 0.6rem;
            border-bottom: 1px solid #F0E2D5;
        }
        tbody td { padding: 0.55rem 0.6rem; border-bottom: 1px solid #F7EFE7; vertical-align: middle; }
        .an-ta-right { text-align: right; }
        .an-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .an-empty { text-align: center; padding: 1.5rem 1rem; color: #8B7A72; }
        .an-section-title { font-size: 0.9rem; font-weight: 700; margin: 0 0 0.5rem; }

        .an-actions-bar { display: flex; justify-content: flex-end; margin-bottom: 0.75rem; }
        .an-actions-bar button {
            font-family: inherit; font-size: 0.8rem; font-weight: 700;
            padding: 0.5rem 1rem; border-radius: 10px; border: 1px solid #F6B49B;
            background: #fff; color: #C0392B; cursor: pointer;
        }

        @media print {
            .an-actions-bar { display: none !important; }
            body { padding: 0; }
            .an-kpi-card { break-inside: avoid; }
            tr { page-break-inside: avoid; break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="an-actions-bar no-print">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    <div class="print-header">
        <h2>Peachy Cakes &amp; Deli Cafe</h2>
        <p style="font-weight:600;">Analytics Report</p>
        <p>Branch: {{ $branchName }}</p>
        <p>Period: {{ $periodStart->format('M d, Y') }} &ndash; {{ $periodEnd->format('M d, Y') }}</p>
        <hr>
    </div>

    <div class="an-kpi-grid">
        <div class="an-kpi-card">
            <p class="an-kpi-label">Total Sales</p>
            <p class="an-kpi-value">₱{{ number_format($totalSales, 2) }}</p>
        </div>
        <div class="an-kpi-card">
            <p class="an-kpi-label">Total Orders</p>
            <p class="an-kpi-value">{{ number_format($totalOrders) }}</p>
        </div>
        <div class="an-kpi-card">
            <p class="an-kpi-label">Average Order Value</p>
            <p class="an-kpi-value">{{ is_null($averageOrderValue) ? '&mdash;' : '₱' . number_format($averageOrderValue, 2) }}</p>
        </div>
        <div class="an-kpi-card">
            <p class="an-kpi-label">Average Rating</p>
            <p class="an-kpi-value">{{ is_null($averageRating) ? '&mdash;' : number_format($averageRating, 2) . ' / 5' }}</p>
        </div>
    </div>

    <h3 class="an-section-title">Sales per Day</h3>
    @if(empty($dailySales['labels']))
        <p class="an-empty">No days in this range.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="an-ta-right">Sales</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dailySales['labels'] as $i => $label)
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="an-ta-right an-num">₱{{ number_format($dailySales['values'][$i], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3 class="an-section-title">Top 5 Products (by quantity sold)</h3>
    @if($topProducts->isEmpty())
        <p class="an-empty">No completed orders in this period yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th class="an-ta-right">Qty Sold</th>
                    <th class="an-ta-right">Revenue</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topProducts as $i => $row)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ optional($row->menuItem)->name ?? '(deleted item)' }}</td>
                        <td class="an-ta-right an-num">{{ number_format($row->total_qty) }}</td>
                        <td class="an-ta-right an-num">₱{{ number_format($row->total_revenue, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p style="font-size:0.72rem; color:#8B7A72;">
        Printed by {{ $printedBy }} on {{ $printedAt->format('M d, Y g:i A') }}
    </p>
</body>
</html>

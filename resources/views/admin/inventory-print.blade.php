<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Report - Peachy Cakes &amp; Deli Cafe</title>

    {{--
        Standalone print document, deliberately NOT extending admin.layout —
        same reasoning as analytics-print.blade.php and
        completed-orders-print.blade.php: nothing on this page but the report,
        so there is no sidebar/branch-bar/bell to hide.

        Rendered in-page: printInventory() in inventory.blade.php fetches this
        route's HTML into a hidden iframe and prints from there (see
        printInFrame() in admin.layout), so this view never navigates the
        browser and this document's own Print button below stays available for
        a manual reprint.

        The visual language is analytics-print's, class-for-class — the same
        .print-header block, the same KPI card grid, the same table treatment,
        the same @media print rules. Only the prefix differs (iv- rather than
        an-) so the two documents can be styled apart later without one of them
        silently restyling the other.
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

        .iv-kpi-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
        .iv-kpi-card { border: 1px solid #F0E2D5; border-radius: 8px; padding: 0.75rem 0.9rem; }
        .iv-kpi-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #8B7A72; margin-bottom: 0.3rem; }
        .iv-kpi-value { font-size: 1.15rem; font-weight: 800; color: #3B2A24; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.78rem; margin-bottom: 1.25rem; }
        thead th {
            background: #FDF1E6; font-size: 0.66rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #C0392B; text-align: left; padding: 0.55rem 0.6rem;
            border-bottom: 1px solid #F0E2D5;
        }
        tbody td { padding: 0.55rem 0.6rem; border-bottom: 1px solid #F7EFE7; vertical-align: middle; }
        .iv-ta-right { text-align: right; }
        .iv-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .iv-empty { text-align: center; padding: 1.5rem 1rem; color: #8B7A72; }
        .iv-section-title { font-size: 0.9rem; font-weight: 700; margin: 0 0 0.5rem; }

        /* Status pills. Printed in grey-on-tint rather than colour alone, and
           each keeps its WORD — a report that is photocopied or printed on a
           mono laser must still say which items are out. */
        .iv-badge {
            display: inline-block; font-size: 0.66rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.04em;
            padding: 0.2rem 0.45rem; border-radius: 999px; border: 1px solid;
        }
        .iv-badge-out { background: #FDECEA; border-color: #F5B7B1; color: #A93226; }
        .iv-badge-low { background: #FEF5E7; border-color: #F8C471; color: #B9770E; }
        .iv-badge-ok  { background: #EAF7EF; border-color: #A9DFBF; color: #1E8449; }

        .iv-actions-bar { display: flex; justify-content: flex-end; margin-bottom: 0.75rem; }
        .iv-actions-bar button {
            font-family: inherit; font-size: 0.8rem; font-weight: 700;
            padding: 0.5rem 1rem; border-radius: 10px; border: 1px solid #F6B49B;
            background: #fff; color: #C0392B; cursor: pointer;
        }

        @media print {
            /* Every navigation/action control off the paper. The only control
               this document has is its own Print button; the page it was
               opened from keeps its own toolbar, which never reaches the
               iframe this is rendered in. */
            .iv-actions-bar, .no-print { display: none !important; }
            body { padding: 0; }
            .iv-kpi-card { break-inside: avoid; }
            tr { page-break-inside: avoid; break-inside: avoid; }
            /* The header repeats at the top of each printed page, so page 3 of
               a long stock list still says which branch and when. */
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>
@php
    /*
     * THE STATUS RULE — out first, then low, then in stock.
     *
     * Identical to admin.inventory's own @php block and to exportInventory():
     *
     *      OUT OF STOCK : quantity <= 0
     *      LOW STOCK    : quantity > 0 AND quantity <= low_stock_alert
     *      IN STOCK     : everything else
     *
     * Evaluated out-before-low, and $isLow carries !$isOut, so a row with
     * quantity 0 and low_stock_alert 10 satisfies both conditions on paper but
     * can only land in one bucket — the same bucket the screen puts it in.
     * This is not a second rule invented for the report; it is the page's rule,
     * applied to the page's rows.
     *
     * Counted in the SAME pass that renders the rows, so the summary strip
     * below cannot drift from the list above it.
     */
    $countOut = 0;
    $countLow = 0;
    $countOk = 0;
    $totalStockValue = 0;

    $rows = [];
    foreach ($inventory as $item) {
        $isOut = $item->quantity <= 0;
        $isLow = !$isOut && $item->quantity <= $item->low_stock_alert;

        if ($isOut)      { $countOut++; }
        elseif ($isLow)  { $countLow++; }
        else             { $countOk++; }

        $totalStockValue += (float) $item->quantity * (float) $item->unit_cost;

        $rows[] = [
            'item'   => $item,
            'status' => $isOut ? 'Out of Stock' : ($isLow ? 'Low Stock' : 'In Stock'),
            'class'  => $isOut ? 'iv-badge-out' : ($isLow ? 'iv-badge-low' : 'iv-badge-ok'),
        ];
    }

    /*
     * 3 decimals then trimmed, matching the on-screen table and the CSV:
     * inventory.quantity is decimal(12,3) and recipes deduct fractional
     * amounts, so printing at 2 would round stock away on the paper the
     * stocktake is done against.
     */
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
@endphp

    <div class="iv-actions-bar no-print">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    <div class="print-header">
        <h2>Peachy Cakes &amp; Deli Cafe</h2>
        <p style="font-weight:600;">Inventory Report</p>
        <p>Branch: <span data-testid="print-branch">{{ $branchName }}</span></p>
        <p>Generated: <span data-testid="print-generated">{{ $printedAt->format('M d, Y g:i A') }}</span></p>
        <hr>
    </div>

    <div class="iv-kpi-grid">
        <div class="iv-kpi-card">
            <p class="iv-kpi-label">Total Items</p>
            <p class="iv-kpi-value">{{ number_format(count($rows)) }}</p>
        </div>
        <div class="iv-kpi-card">
            <p class="iv-kpi-label">In Stock</p>
            <p class="iv-kpi-value">{{ number_format($countOk) }}</p>
        </div>
        <div class="iv-kpi-card">
            <p class="iv-kpi-label">Low Stock</p>
            <p class="iv-kpi-value">{{ number_format($countLow) }}</p>
        </div>
        <div class="iv-kpi-card">
            <p class="iv-kpi-label">Out of Stock</p>
            <p class="iv-kpi-value">{{ number_format($countOut) }}</p>
        </div>
        <div class="iv-kpi-card">
            <p class="iv-kpi-label">Total Stock Value</p>
            <p class="iv-kpi-value">₱{{ number_format($totalStockValue, 2) }}</p>
        </div>
    </div>

    <h3 class="iv-section-title">Stock on Hand</h3>
    @if(empty($rows))
        <p class="iv-empty">No inventory items for this branch.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th class="iv-ta-right">Quantity</th>
                    <th>Unit</th>
                    <th class="iv-ta-right">Low Stock Alert</th>
                    <th class="iv-ta-right">Stock Value</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['item']->item_name }}</td>
                        <td class="iv-ta-right iv-num">{{ $trim($row['item']->quantity) }}</td>
                        <td>{{ $row['item']->unit }}</td>
                        <td class="iv-ta-right iv-num">{{ $trim($row['item']->low_stock_alert) }}</td>
                        <td class="iv-ta-right iv-num">₱{{ number_format($row['item']->quantity * $row['item']->unit_cost, 2) }}</td>
                        <td><span class="iv-badge {{ $row['class'] }}">{{ $row['status'] }}</span></td>
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

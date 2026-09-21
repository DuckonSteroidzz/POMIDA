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

        /* Demand Forecast (Phase 2c). The forecast has no chart on paper, so
           it prints as prose plus tables; the caveat line is deliberately part
           of the printed sheet, because a printout outlives the screen it came
           from and an estimate must not be readable later as a measurement. */
        .an-fc-line { margin: 0 0 0.3rem; }
        .an-fc-note { font-size: 0.72rem; color: #8B7A72; margin: 0.35rem 0 1rem; }

        /* Mirrors .date-range-notice in admin.layout. Repeated rather than
           shared because this document deliberately does not extend the
           layout — see the note at the top of this file. */
        .an-date-notice {
            display: flex; align-items: flex-start; gap: 0.5rem;
            background: #FFF8E6; border: 1px solid #F2D89B; border-radius: 8px;
            padding: 0.6rem 0.8rem; margin-bottom: 1rem;
            font-size: 0.76rem; line-height: 1.45; color: #7A5B12;
        }

        .an-actions-bar { display: flex; justify-content: flex-end; margin-bottom: 0.75rem; }
        .an-actions-bar button {
            font-family: inherit; font-size: 0.8rem; font-weight: 700;
            padding: 0.5rem 1rem; border-radius: 10px; border: 1px solid #F6B49B;
            background: #fff; color: #C0392B; cursor: pointer;
        }

        /* Mirrors admin.analytics's .mpc-badge-* palette so the same status is
           never described in two different colors between screen and paper. */
        .an-cap-note { font-size: 0.72rem; color: #8B7A72; margin: -0.35rem 0 0.6rem; }
        .an-cap-branch { display: block; font-size: 0.66rem; color: #999; }
        .an-cap-badge {
            display: inline-block; padding: 0.1rem 0.45rem; border-radius: 999px;
            font-size: 0.66rem; font-weight: 600; white-space: nowrap;
        }
        .an-cap-badge-ok   { background: #e8f5ec; color: #2e7d4f; }
        .an-cap-badge-low  { background: #fff3e2; color: #b26a12; }
        .an-cap-badge-out  { background: #fdeaea; color: #b3261e; }
        .an-cap-badge-none { background: #f0f0f0; color: #777; }

        /* Automated Insights — prose, not a table: this sheet already carries
           four tables and a fifth would push the report past one readable
           page. Each item is one sentence the owner can act on or ignore. */
        .an-insight-list { margin: 0 0 1rem 1.1rem; padding: 0; font-size: 0.76rem; line-height: 1.5; }
        .an-insight-list li { margin-bottom: 0.35rem; }

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

    {{-- Deliberately NOT .no-print: a printed sheet outlives the browser tab
         it came from, so if the requested range was corrected or discarded the
         paper has to say so too. Silent on every ordinary request. --}}
    @if(!empty($dateNotice))
        <div class="an-date-notice" data-testid="date-range-notice">
            <span>{{ $dateNotice }}</span>
        </div>
    @endif

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

    {{-- Demand Forecast (Phase 2c). Paper has no chart, so the projection is
         printed as the figures the chart would have drawn. Same service, same
         arguments, same already-fetched series as the screen — and the same
         refusal to print "₱0" when the data cannot support a forecast. --}}
    <h3 class="an-section-title">Demand Forecast</h3>
    @if($forecast['sufficient'])
        <p class="an-fc-line">
            <strong>Projected Next {{ $forecast['horizon_days'] }} Days:</strong>
            ₱{{ number_format($forecast['total_next_7_days'], 2) }}
            (about ₱{{ number_format($forecast['next_day'], 2) }} per day)
        </p>
        <p class="an-fc-line">
            <strong>Historical period:</strong>
            {{ $periodStart->format('M d, Y') }} &ndash; {{ $periodEnd->format('M d, Y') }}
            &nbsp;&bull;&nbsp;
            <strong>Forecast period:</strong>
            {{ \Carbon\Carbon::parse($forecast['forecast_start'])->format('M d, Y') }}
            &ndash;
            {{ \Carbon\Carbon::parse($forecast['forecast_end'])->format('M d, Y') }}
        </p>
        <p class="an-fc-note">
            Estimated by moving average over the last {{ $forecast['observations_used'] }}
            day{{ $forecast['observations_used'] === 1 ? '' : 's' }} of the historical period.
            A projection from past sales, not a guaranteed figure.
        </p>
    @else
        <p class="an-empty">
            {{ $forecast['message'] }}
            Sales were recorded on {{ $forecast['days_with_sales'] }} of
            {{ $forecast['historical_days'] }} day{{ $forecast['historical_days'] === 1 ? '' : 's' }}
            in this period.
        </p>
    @endif

    @if(!empty($weekdayDemand))
        <h3 class="an-section-title">Historical Demand by Day of Week</h3>
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th class="an-ta-right">Historical Average</th>
                    <th class="an-ta-right">Days Observed</th>
                    <th class="an-ta-right">Days with Sales</th>
                </tr>
            </thead>
            <tbody>
                @foreach($weekdayDemand as $row)
                    <tr>
                        <td>{{ $row['day'] }}</td>
                        <td class="an-ta-right an-num">₱{{ number_format($row['average'], 2) }}</td>
                        <td class="an-ta-right an-num">{{ $row['observations'] }}</td>
                        <td class="an-ta-right an-num">{{ $row['days_with_sales'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="an-fc-note">
            Descriptive history across the selected period, not a prediction. Every occurrence of
            each weekday is counted, including days with no sales.
        </p>
    @endif

    <h3 class="an-section-title">Menu Demand Forecast</h3>
    @if(empty($menuForecast['rows']))
        <p class="an-empty">No menu items sold in this period, so there is nothing to forecast from.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="an-ta-right">Projected / Day</th>
                    <th class="an-ta-right">Projected Next {{ $menuForecast['horizon_days'] }} Days</th>
                    <th class="an-ta-right">Days with Sales</th>
                </tr>
            </thead>
            <tbody>
                @foreach($menuForecast['rows'] as $row)
                    <tr>
                        <td>{{ $row['menu_item_name'] }}</td>
                        @if($row['sufficient'])
                            <td class="an-ta-right an-num">{{ number_format($row['forecast_qty_per_day'], 2) }}</td>
                            <td class="an-ta-right an-num">{{ number_format($row['forecast_qty_next_7_days'], 1) }}</td>
                        @else
                            <td colspan="2" class="an-ta-right">{{ $row['message'] }}</td>
                        @endif
                        <td class="an-ta-right an-num">{{ $row['days_with_sales'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="an-fc-note">
            Projected units per item over the next {{ $menuForecast['horizon_days'] }} days, from the
            moving average of the last {{ $menuForecast['window_days'] }} days. An item needs sales on
            at least {{ $menuForecast['min_days_with_sales'] }} days before a forecast is shown.
            {{ $menuForecast['items_forecastable'] }} of {{ $menuForecast['items_with_history'] }}
            item{{ $menuForecast['items_with_history'] === 1 ? '' : 's' }} sold in this period qualify.
        </p>
    @endif

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

    {{-- MENU PERFORMANCE (Phase 2d). Replaces the old "Top 5 Products" table,
         whose revenue was aggregated separately from the Summary screen's —
         the Phase 1 finding. Every money column here comes from
         ProfitCalculationService, the same service the screen and the CSV read,
         so the paper cannot quote a different figure than either of them.
         Revenue is per-item and therefore before order-level discounts; the
         heading says so, and the net figures are stated once, below. --}}
    <h3 class="an-section-title">Menu Performance</h3>
    @if(empty($performanceRows))
        <p class="an-empty">No completed orders in this period yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th class="an-ta-right">Qty Sold</th>
                    <th class="an-ta-right">Revenue</th>
                    <th class="an-ta-right">COGS</th>
                    <th class="an-ta-right">Gross Profit</th>
                    <th class="an-ta-right">Margin</th>
                </tr>
            </thead>
            <tbody>
                @foreach($performanceRows as $i => $row)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>
                            {{ $row['menu_item_name'] }}
                            @if($isAllBranches && $row['branch_name'])
                                <span class="an-cap-branch">{{ $row['branch_name'] }}</span>
                            @endif
                        </td>
                        <td class="an-ta-right an-num">{{ number_format($row['quantity_sold']) }}</td>
                        <td class="an-ta-right an-num">₱{{ number_format($row['revenue'], 2) }}</td>
                        <td class="an-ta-right an-num">₱{{ number_format($row['cogs'], 2) }}</td>
                        <td class="an-ta-right an-num">₱{{ number_format($row['gross_profit'], 2) }}</td>
                        <td class="an-ta-right an-num">
                            {{ $row['margin_percent'] === null ? '—' : number_format($row['margin_percent'], 1) . '%' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="an-fc-note">
            Revenue is the menu price before order-level discounts, which belong to the order rather
            than to any one item. For the period as a whole: net revenue
            ₱{{ number_format($intel['financials']['net_revenue'], 2) }} after
            ₱{{ number_format($intel['financials']['discounts'], 2) }} of discounts, gross profit
            ₱{{ number_format($intel['financials']['gross_profit'], 2) }}@if($intel['financials']['margin_percent'] !== null)
                ({{ number_format($intel['financials']['margin_percent'], 1) }}% margin of net revenue)@endif,
            across {{ number_format($intel['financials']['order_count']) }} completed
            order{{ $intel['financials']['order_count'] === 1 ? '' : 's' }}.
        </p>
    @endif

    {{-- CURRENT PRODUCTION CAPACITY — a live inventory snapshot, deliberately
         NOT part of the historical report above. Sales/orders/AOV/rating and
         the two tables above are for the Period stated in the header; this
         one is "as of right now" and says so on its own line, the same
         distinction the live Analytics page draws (see its Menu Production
         Capacity card). Uses ProductionCapacityService::forBranchScope() —
         the exact service the live page calls — so this number and the
         screen's number can never disagree. --}}
    <h3 class="an-section-title">Current Production Capacity</h3>
    <p class="an-cap-note">
        Snapshot as of {{ $printedAt->format('M d, Y g:i A') }} &mdash; current stock on hand, not the
        {{ $periodStart->format('M d, Y') }} &ndash; {{ $periodEnd->format('M d, Y') }} report period above.
    </p>
    @if(empty($productionCapacity))
        <p class="an-empty">No available menu items in this branch yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Menu Item</th>
                    <th class="an-ta-right">Current Capacity</th>
                    <th>Bottleneck Ingredient</th>
                    <th>Capacity Status</th>
                    {{-- Phase 2d adds two forward-looking columns to the Phase
                         2b.1 table rather than redesigning it: Capacity Status
                         is unchanged and still says what is on the shelf, and
                         the two new columns say what the projection makes of
                         it. Coverage and the risk verdict are omitted from
                         paper on purpose — seven columns is where this table
                         stops being readable at print width, and the risk
                         verdict is derived from the shortage that is already
                         printed beside it. --}}
                    <th class="an-ta-right">Forecast Demand</th>
                    <th class="an-ta-right">Potential Shortage</th>
                </tr>
            </thead>
            <tbody>
                @foreach($intel['capacity_rows'] as $row)
                    @php
                        // Same vocabulary as admin.analytics's Menu Production
                        // Capacity card — one status can never read two ways
                        // across screen and paper.
                        $capReasons = \App\Services\ProductionCapacityService::class;

                        if (!$row['is_measurable']) {
                            $capStatusLabel = $row['unavailable_reason'] === $capReasons::REASON_CROSS_BRANCH_INGREDIENT
                                ? 'Unavailable'
                                : 'No Recipe Set';
                            $capStatusClass = 'an-cap-badge-none';
                        } elseif ($row['capacity'] <= 0) {
                            $capStatusLabel = 'Out of Stock';
                            $capStatusClass = 'an-cap-badge-out';
                        } elseif ($row['capacity'] <= $lowCapacityThreshold) {
                            $capStatusLabel = 'Low Stock';
                            $capStatusClass = 'an-cap-badge-low';
                        } else {
                            $capStatusLabel = 'In Stock';
                            $capStatusClass = 'an-cap-badge-ok';
                        }
                    @endphp
                    <tr>
                        <td>
                            {{ $row['menu_item_name'] }}
                            @if($isAllBranches && $row['branch_name'])
                                <span class="an-cap-branch">{{ $row['branch_name'] }}</span>
                            @endif
                        </td>
                        <td class="an-ta-right an-num an-cap-num">
                            {{ $row['is_measurable'] ? number_format($row['capacity']) : '—' }}
                        </td>
                        <td class="an-cap-bottleneck">{{ $row['is_measurable'] && $row['bottleneck_name'] ? $row['bottleneck_name'] : '—' }}</td>
                        <td><span class="an-cap-badge {{ $capStatusClass }}">{{ $capStatusLabel }}</span></td>

                        {{-- Projected, not measured — and "Insufficient Data"
                             rather than 0 wherever the history cannot support
                             a figure. A printed sheet outlives the screen it
                             came from, so a fabricated zero here would be read
                             as a measurement months later. --}}
                        <td class="an-ta-right an-num">
                            {{ $row['forecast_sufficient']
                                ? number_format($row['forecast_qty_next_7_days'], 1)
                                : 'Insufficient Data' }}
                        </td>
                        <td class="an-ta-right an-num">
                            @if(!$row['is_measurable'])
                                Unavailable
                            @elseif(!$row['forecast_sufficient'])
                                Insufficient Data
                            @elseif($row['has_shortage'])
                                {{ number_format($row['potential_shortage'], 1) }}
                            @else
                                None
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="an-fc-note">
            Forecast Demand is a projection for the next {{ $intel['horizon_days'] }} days, estimated
            from the {{ $periodStart->format('M d, Y') }} &ndash; {{ $periodEnd->format('M d, Y') }}
            period by moving average. Potential Shortage is that projection minus the live capacity
            beside it, and is shown only where the projection exceeds capacity &mdash; "None" means
            capacity covers it. Neither is a guaranteed figure.
        </p>
    @endif

    {{-- AUTOMATED INSIGHTS (Phase 2d). The same list the screen renders, from
         the same result — this is not a print-only summary written separately.
         Kept to prose so it does not become a second table on an already busy
         sheet, and phrased as decision support: nothing here instructs the
         owner to buy a quantity or to withdraw a product. The application
         contains no AI or ML model and the heading does not claim one. --}}
    <h3 class="an-section-title">Automated Insights &amp; Recommendations</h3>
    @if(empty($intel['insights']))
        <p class="an-empty">Nothing in the current data meets an alert condition for this branch and period.</p>
    @else
        <ul class="an-insight-list">
            @foreach($intel['insights'] as $insight)
                <li><strong>{{ $insight['title'] }}:</strong> {{ $insight['message'] }}</li>
            @endforeach
        </ul>
        <p class="an-fc-note">
            Generated from the figures printed above &mdash; current stock, the selected period's
            sales, and a moving-average projection. Estimates and suggestions, not instructions.
        </p>
    @endif

    <p style="font-size:0.72rem; color:#8B7A72;">
        Printed by {{ $printedBy }} on {{ $printedAt->format('M d, Y g:i A') }}
    </p>
</body>
</html>

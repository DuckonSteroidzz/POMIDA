@extends('admin.layout')

@section('title', 'Analytics - Peachy Admin')

@section('content')

<p class="page-title">Data Analytics &amp; Business Insights</p>

<p style="font-size:0.78rem; color:#666; margin-bottom:1rem;">
    Viewing: <strong>{{ $branchName }}</strong>
    &nbsp;•&nbsp; Time window: last 30 days for most metrics
    &nbsp;•&nbsp; Rule-based recommendations
</p>

{{-- ══════════ KPI ROW ══════════
     Sales Today / Week / Month live on the Summary page — Analytics keeps only
     the two figures unique to this page. --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:0.75rem; margin-bottom:1rem;">

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Recommendations</p>
        <p style="font-size:1.25rem; font-weight:700; color:#F4845F; margin-bottom:0.2rem;">{{ count($recommendations) }}</p>
        <p style="font-size:0.72rem; color:#666;">actionable insights</p>
    </div>

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Average Rating</p>
        @if($averageRating !== null)
            <p style="font-size:1.25rem; font-weight:700; color:#333; margin-bottom:0.2rem;">
                <i class="bi bi-star-fill" style="color:#F4845F;"></i> {{ number_format($averageRating, 2) }} / 5
            </p>
            <p style="font-size:0.72rem; color:#666;">last 30 days</p>
        @else
            <p style="font-size:1.25rem; font-weight:700; color:#ccc; margin-bottom:0.2rem;">—</p>
            <p style="font-size:0.72rem; color:#666;">No ratings yet</p>
        @endif
    </div>
</div>

{{-- ══════════ TOP: RECOMMENDATIONS PANEL (full-width highlight) ══════════ --}}
<div class="content-card" style="margin-bottom:1rem; border-top:3px solid #F4845F;">
    <p style="font-size:0.9rem; font-weight:700; color:#333; margin-bottom:0.75rem;">
        <i class="bi bi-lightbulb"></i> Analytics-Based Recommendations
    </p>

    @if(count($recommendations) === 0)
        <p style="font-size:0.8rem; color:#888;">No recommendations right now. Operations look healthy in this period.</p>
    @else
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:0.6rem;">
            @foreach($recommendations as $rec)
                @php
                    $colors = [
                        'critical' => ['#FDECEA', '#C0392B'],
                        'warning'  => ['#FFF8E1', '#B7791F'],
                        'success'  => ['#E8F5E9', '#2E7D32'],
                        'info'     => ['#E8F0FE', '#1A56DB'],
                    ];
                    [$bg, $fg] = $colors[$rec['level']] ?? ['#f5f5f5', '#555'];
                @endphp
                <div style="background:{{ $bg }}; border-left:4px solid {{ $fg }}; padding:0.6rem 0.75rem; border-radius:6px;">
                    <p style="font-size:0.78rem; font-weight:700; color:{{ $fg }}; margin-bottom:0.2rem;">
                        <i class="bi {{ $rec['icon'] }}"></i> {{ $rec['title'] }}
                    </p>
                    <p style="font-size:0.78rem; color:#555; line-height:1.45;">{{ $rec['message'] }}</p>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ══════════ MIDDLE: FORECAST (left) + SALES BY CATEGORY (right) ══════════ --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:1rem; margin-bottom:1rem; align-items:start;">

    {{-- Left: Sales Forecast (Simple Linear Regression) --}}
    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
            <i class="bi bi-graph-up-arrow"></i> Sales Forecast — Next {{ $salesForecast['forecast_days'] ?? 7 }} Days (Simple Linear Regression)
        </p>

        @if($salesForecast['insufficient_data'] ?? true)
            <p style="font-size:0.84rem; color:#888; line-height:1.45;">
                Not enough sales data yet to forecast ({{ $salesForecast['days_with_sales'] ?? 0 }} day(s) with completed orders
                in the last {{ $salesForecast['lookback_days'] ?? 30 }} days — at least 5 are needed). Check back once more orders come in.
            </p>
        @else
            @php
                $trendColors = [
                    'increasing' => ['#E8F5E9', '#2E7D32', 'bi-arrow-up-right'],
                    'decreasing' => ['#FDECEA', '#C0392B', 'bi-arrow-down-right'],
                    'flat'       => ['#F0F0F0', '#666', 'bi-dash'],
                ];
                [$trendBg, $trendFg, $trendIcon] = $trendColors[$salesForecast['trend']] ?? $trendColors['flat'];
            @endphp

            @if($salesForecast['low_sales_alert'])
            <div style="background:#FFF8E1; border:1px solid #F1C40F; color:#B7791F; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.75rem; font-weight:600; margin-bottom:0.75rem;">
                <i class="bi bi-exclamation-triangle"></i> Forecast for tomorrow is more than 20% below the trailing 7-day average — sales may be slowing down.
            </div>
            @endif

            <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center; margin-bottom:0.75rem;">
                <span style="background:{{ $trendBg }}; color:{{ $trendFg }}; padding:0.25rem 0.6rem; border-radius:16px; font-size:0.72rem; font-weight:700; text-transform:capitalize;">
                    <i class="bi {{ $trendIcon }}"></i> {{ $salesForecast['trend'] }} trend
                </span>
                <span style="font-size:0.7rem; color:#888;">
                    slope: ₱{{ number_format($salesForecast['slope'], 2) }}/day &nbsp;•&nbsp; based on last {{ $salesForecast['lookback_days'] }} days
                </span>
            </div>

            <div style="height:240px;">
                <canvas id="forecastChart"></canvas>
            </div>
        @endif
    </div>

    {{-- Right: Sales by Category (pie) --}}
    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
            <i class="bi bi-pie-chart"></i> Sales by Category (last 30 days)
        </p>
        <div style="height:240px; display:flex; align-items:center; justify-content:center;">
            @if($salesByCategory->isEmpty())
                <p style="font-size:0.78rem; color:#888;">No category data yet.</p>
            @else
                <canvas id="categoryChart" style="max-height:230px;"></canvas>
            @endif
        </div>
    </div>
</div>

{{-- ══════════ BOTTOM: INVENTORY RISK (left) + BRANCH PERFORMANCE (right) ══════════ --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:1rem; margin-bottom:1rem; align-items:start;">

    {{-- Left: Inventory Risk & Slow Movers --}}
    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.75rem;">
            <i class="bi bi-box-seam"></i> Inventory Risk &amp; Slow Movers
        </p>

        <p style="font-size:0.8rem; font-weight:700; color:#C0392B; margin-bottom:0.4rem;">
            <i class="bi bi-exclamation-octagon"></i> Out of Stock ({{ $outOfStock->count() }})
        </p>
        @if($outOfStock->isEmpty())
            <p style="font-size:0.78rem; color:#888; margin-bottom:0.75rem;">No items are out of stock.</p>
        @else
            <ul style="margin:0 0 0.75rem; padding-left:1rem; font-size:0.78rem;">
                @foreach($outOfStock as $item)
                    <li style="margin-bottom:0.2rem;">
                        <strong>{{ $item->item_name }}</strong>
                        <span style="color:#888;">({{ $item->unit }})</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p style="font-size:0.8rem; font-weight:700; color:#B7791F; margin-bottom:0.4rem;">
            <i class="bi bi-exclamation-triangle"></i> Low Stock ({{ $lowStock->count() }})
        </p>
        @if($lowStock->isEmpty())
            <p style="font-size:0.78rem; color:#888; margin-bottom:0.75rem;">No low-stock items.</p>
        @else
            <ul style="margin:0 0 0.75rem; padding-left:1rem; font-size:0.78rem;">
                @foreach($lowStock as $item)
                    <li style="margin-bottom:0.2rem;">
                        <strong>{{ $item->item_name }}</strong>
                        — {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->unit }}
                        <span style="color:#888;">(alert ≤ {{ rtrim(rtrim(number_format($item->low_stock_alert, 2), '0'), '.') }})</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p style="font-size:0.8rem; font-weight:700; color:#555; margin-bottom:0.4rem;">
            <i class="bi bi-hourglass"></i> Slow Movers ({{ $slowMovers->count() }})
        </p>
        @if($slowMovers->isEmpty())
            <p style="font-size:0.78rem; color:#888;">No slow-moving items detected.</p>
        @else
            <ul style="margin:0; padding-left:1rem; font-size:0.78rem;">
                @foreach($slowMovers as $item)
                    <li style="margin-bottom:0.2rem;">
                        <strong>{{ $item->item_name }}</strong>
                        — {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->unit }}
                    </li>
                @endforeach
            </ul>
            <p style="font-size:0.7rem; color:#888; margin-top:0.4rem;">No stock movement in the last 30 days.</p>
        @endif

        @if($linkedToBest->isNotEmpty())
        <p style="font-size:0.8rem; font-weight:700; color:#333; margin:0.9rem 0 0.4rem;">
            <i class="bi bi-link-45deg"></i> Ingredients of Best-Selling Menu Items
        </p>
        <p style="font-size:0.72rem; color:#666; margin-bottom:0.5rem;">Monitor these closely — they feed your top sellers.</p>
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
            @foreach($linkedToBest as $item)
                @php
                    $isOut = $item->quantity <= 0;
                    $isLow = $item->quantity > 0 && $item->quantity <= $item->low_stock_alert;
                    $badgeColor = $isOut ? '#C0392B' : ($isLow ? '#B7791F' : '#4CAF50');
                @endphp
                <span style="background:{{ $badgeColor }}; color:white; padding:0.25rem 0.6rem; border-radius:16px; font-size:0.72rem; font-weight:600;">
                    {{ $item->item_name }} ({{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->unit }})
                </span>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Right: Branch Performance Table
         Shown on every branch selection, because it is a comparison BETWEEN
         branches. Every figure is a straight SUM of completed orders in the same
         window Sales per Branch already uses — nothing here is forecast. --}}
    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.2rem;">
            <i class="bi bi-bar-chart-steps"></i> Branch Performance (last {{ $branchPerformance['window_days'] }} days)
        </p>
        <p style="font-size:0.7rem; color:#777; margin-bottom:0.8rem;">
            Completed orders only. A branch is ranked once it has at least
            {{ $branchPerformance['min_orders'] }} completed orders across
            {{ $branchPerformance['min_days'] }} different days.
        </p>

        <div style="overflow-x:auto;">
        <table style="width:100%; font-size:0.78rem; border-collapse:collapse;">
            <thead>
                <tr style="background:#F4845F; color:white;">
                    <th style="padding:0.4rem; text-align:left;">Branch</th>
                    <th style="padding:0.4rem; text-align:right;">Sales</th>
                    <th style="padding:0.4rem; text-align:center;">Orders</th>
                    <th style="padding:0.4rem; text-align:left;">Standing</th>
                </tr>
            </thead>
            <tbody>
                @foreach($branchPerformance['branches'] as $bp)
                    @php
                        $isSelected = $selectedBranch !== 'all' && (int) $selectedBranch === $bp['id'];
                    @endphp
                    <tr style="border-bottom:1px solid #f0f0f0; {{ $isSelected ? 'background:#FFF6F1;' : '' }}">
                        <td style="padding:0.45rem; font-weight:600;">
                            {{ $bp['name'] }}
                            @if($isSelected)
                                <span style="font-size:0.65rem; color:#C0392B; font-weight:700;">(selected)</span>
                            @endif
                            @unless($bp['is_active'])
                                <span style="font-size:0.65rem; color:#888;">(inactive)</span>
                            @endunless
                        </td>
                        <td style="padding:0.45rem; text-align:right; font-weight:600; color:#F4845F;">
                            ₱{{ number_format($bp['sales'], 2) }}
                        </td>
                        <td style="padding:0.45rem; text-align:center; color:#555;">
                            {{ $bp['orders'] }}
                            <span style="font-size:0.68rem; color:#999;">/ {{ $bp['days_with_sales'] }}d</span>
                        </td>
                        <td style="padding:0.45rem;">
                            @if(!$bp['comparable'])
                                <span style="background:#ECEFF1; color:#546E7A; padding:0.12rem 0.5rem; border-radius:12px; font-size:0.68rem; font-weight:600;">
                                    Not enough data to compare
                                </span>
                            @elseif($bp['is_strongest'])
                                <span style="background:#E6F4EA; color:#1E7A3C; padding:0.12rem 0.5rem; border-radius:12px; font-size:0.68rem; font-weight:700;">
                                    <i class="bi bi-arrow-up-short"></i> Strongest
                                </span>
                            @elseif($bp['is_weakest'])
                                <span style="background:#FDECEA; color:#B3261E; padding:0.12rem 0.5rem; border-radius:12px; font-size:0.68rem; font-weight:700;">
                                    <i class="bi bi-arrow-down-short"></i> Weakest
                                </span>
                                <span style="font-size:0.68rem; color:#777;">
                                    {{ number_format($bp['pct_below_strongest'], 1) }}% below {{ $branchPerformance['strongest']['name'] }}
                                </span>
                            @elseif($bp['pct_below_strongest'] !== null && $bp['pct_below_strongest'] > 0)
                                <span style="font-size:0.7rem; color:#555;">
                                    {{ number_format($bp['pct_below_strongest'], 1) }}% below {{ $branchPerformance['strongest']['name'] }}
                                </span>
                            @else
                                <span style="font-size:0.7rem; color:#777;">Only branch with enough data</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        <p style="margin-top:0.75rem; padding:0.6rem 0.75rem; background:#FFF6F1; border-left:3px solid #F4845F; border-radius:6px; font-size:0.74rem; color:#5A4038; line-height:1.5;">
            {{ $branchPerformance['insight'] }}
        </p>
    </div>
</div>

{{-- ══════════ BEST / LEAST SELLERS (compact reference) ══════════ --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1rem; margin-bottom:1rem;">
    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
            <i class="bi bi-star-fill" style="color:#F4845F;"></i> Best Sellers (last 30 days)
        </p>
        @if($bestSellers->isEmpty())
            <p style="font-size:0.78rem; color:#888;">No completed orders in this period yet.</p>
        @else
            <div style="overflow-x:auto;">
            <table style="width:100%; font-size:0.78rem; border-collapse:collapse;">
                <thead>
                    <tr style="background:#F4845F; color:white;">
                        <th style="padding:0.4rem; text-align:left;">#</th>
                        <th style="padding:0.4rem; text-align:left;">Item</th>
                        <th style="padding:0.4rem; text-align:right;">Qty Sold</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($bestSellers as $i => $row)
                        <tr style="border-bottom:1px solid #f0f0f0;">
                            <td style="padding:0.4rem;">{{ $i + 1 }}</td>
                            <td style="padding:0.4rem;">{{ optional($row->menuItem)->name ?? '—' }}</td>
                            <td style="padding:0.4rem; text-align:right; font-weight:600; color:#F4845F;">{{ $row->total_qty }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
            <i class="bi bi-graph-down" style="color:#888;"></i> Least Sellers (last 30 days)
        </p>
        @if($leastSellers->isEmpty())
            <p style="font-size:0.78rem; color:#888;">Not enough data to rank low-selling items.</p>
        @else
            <div style="overflow-x:auto;">
            <table style="width:100%; font-size:0.78rem; border-collapse:collapse;">
                <thead>
                    <tr style="background:#888; color:white;">
                        <th style="padding:0.4rem; text-align:left;">#</th>
                        <th style="padding:0.4rem; text-align:left;">Item</th>
                        <th style="padding:0.4rem; text-align:right;">Qty Sold</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($leastSellers as $i => $row)
                        <tr style="border-bottom:1px solid #f0f0f0;">
                            <td style="padding:0.4rem;">{{ $i + 1 }}</td>
                            <td style="padding:0.4rem;">{{ optional($row->menuItem)->name ?? '—' }}</td>
                            <td style="padding:0.4rem; text-align:right; color:#666;">{{ $row->total_qty }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>

{{-- ══════════ SALES PER BRANCH (only on All Branches) ══════════ --}}
@if($isAllBranches)
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
        <i class="bi bi-building"></i> Sales per Branch (last 30 days)
    </p>
    @if($salesPerBranch->isEmpty())
        <p style="font-size:0.78rem; color:#888;">No branches configured yet.</p>
    @else
        <div style="overflow-x:auto;">
        <table style="width:100%; font-size:0.78rem; border-collapse:collapse;">
            <thead>
                <tr style="background:#F4845F; color:white;">
                    <th style="padding:0.4rem; text-align:left;">Branch</th>
                    <th style="padding:0.4rem; text-align:center;">Status</th>
                    <th style="padding:0.4rem; text-align:right;">30-Day Sales</th>
                </tr>
            </thead>
            <tbody>
                @foreach($salesPerBranch as $b)
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:0.4rem; font-weight:600;">{{ $b->name }}</td>
                        <td style="padding:0.4rem; text-align:center;">
                            <span style="background:{{ $b->is_active ? '#4CAF50' : '#888' }}; color:white; padding:0.1rem 0.5rem; border-radius:12px; font-size:0.68rem;">
                                {{ $b->is_active ? 'Open' : 'Closed' }}
                            </span>
                        </td>
                        <td style="padding:0.4rem; text-align:right; font-weight:600; color:#F4845F;">
                            ₱{{ number_format((float) $b->sales_30d, 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>
@endif

<p style="font-size:0.7rem; color:#888; text-align:center; margin-top:1rem;">
    Descriptive analytics &amp; rule-based recommendations. All thresholds are configurable in <code>AnalyticsService</code>.
</p>

@endsection

@push('scripts')
<script src="/vendor/chart.umd.min.js"></script>
<script>
    // ── Sales Forecast (line, actual + projected)
    @if(!($salesForecast['insufficient_data'] ?? true))
    (function () {
        const el = document.getElementById('forecastChart');
        if (!el) return;

        const historyLabels = {!! json_encode($salesForecast['history']['labels']) !!};
        const historyValues = {!! json_encode($salesForecast['history']['values']) !!};
        const forecastLabels = {!! json_encode(array_column($salesForecast['forecast'], 'label')) !!};
        const forecastValues = {!! json_encode(array_column($salesForecast['forecast'], 'value')) !!};

        const labels = historyLabels.concat(forecastLabels);

        // Actual series: padded with nulls over the forecast range so it stops at "today".
        const actualData = historyValues.concat(forecastLabels.map(() => null));

        // Forecast series: padded with nulls over history, bridged from the last actual
        // point so the projected line connects visually to where actuals end.
        const forecastData = historyValues.map(() => null);
        forecastData[historyValues.length - 1] = historyValues[historyValues.length - 1];
        forecastData.push(...forecastValues);

        new Chart(el.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Actual (₱)',
                        data: actualData,
                        borderColor: '#F4845F',
                        backgroundColor: 'rgba(244,132,95,0.12)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointBackgroundColor: '#F4845F',
                        spanGaps: false
                    },
                    {
                        label: 'Forecast (₱)',
                        data: forecastData,
                        borderColor: '#1A56DB',
                        backgroundColor: 'rgba(26,86,219,0.08)',
                        borderWidth: 2,
                        borderDash: [6, 4],
                        tension: 0.35,
                        fill: true,
                        pointBackgroundColor: '#1A56DB',
                        spanGaps: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, labels: { font: { size: 10, family: 'Poppins' } } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10, family: 'Poppins' } } },
                    y: { grid: { color: '#f0f0f0' }, ticks: { font: { size: 10, family: 'Poppins' } } }
                }
            }
        });
    })();
    @endif

    // ── Sales by Category (pie)
    (function () {
        const el = document.getElementById('categoryChart');
        if (!el) return;
        new Chart(el.getContext('2d'), {
            type: 'pie',
            data: {
                labels: {!! json_encode($salesByCategory->pluck('name')->toArray()) !!},
                datasets: [{
                    data: {!! json_encode($salesByCategory->pluck('total_qty')->map(fn($v) => (int) $v)->toArray()) !!},
                    backgroundColor: ['#F4845F','#C0392B','#F4A460','#FFB6C1','#DEB887','#87CEEB','#98D8C8','#FFD180'],
                    borderWidth: 2,
                    borderColor: 'white'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right', labels: { font: { size: 10, family: 'Poppins' }, padding: 10 } } }
            }
        });
    })();
</script>
@endpush

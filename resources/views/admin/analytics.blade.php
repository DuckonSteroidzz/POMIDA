@extends('admin.layout')

@section('title', 'Analytics - Peachy Admin')

@section('content')

<p class="page-title">Data Analytics &amp; Business Insights</p>

<p style="font-size:0.78rem; color:#666; margin-bottom:1rem;">
    Viewing: <strong>{{ $branchName }}</strong>
    &nbsp;•&nbsp; Time window: last 30 days for most metrics
    &nbsp;•&nbsp; Rule-based recommendations
</p>

{{-- ══════════ KPI ROW ══════════ --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.75rem; margin-bottom:1rem;">

    @php
        $deltaBadge = function ($delta) {
            if ($delta === null) {
                return ['—', '#888', 'bi-dash'];
            }
            if ($delta >= 0) {
                return ['+' . $delta . '%', '#4CAF50', 'bi-arrow-up-right'];
            }
            return [$delta . '%', '#C0392B', 'bi-arrow-down-right'];
        };
        [$todayLabel, $todayColor, $todayIcon]    = $deltaBadge($deltaToday);
        [$weekLabel, $weekColor, $weekIcon]       = $deltaBadge($deltaWeek);
        [$monthLabel, $monthColor, $monthIcon]    = $deltaBadge($deltaMonth);
    @endphp

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Sales Today</p>
        <p style="font-size:1.25rem; font-weight:700; color:#333; margin-bottom:0.2rem;">₱{{ number_format($today, 2) }}</p>
        <p style="font-size:0.72rem; color:{{ $todayColor }}; font-weight:600;">
            <i class="bi {{ $todayIcon }}"></i> {{ $todayLabel }} vs yesterday
        </p>
    </div>

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Sales This Week</p>
        <p style="font-size:1.25rem; font-weight:700; color:#333; margin-bottom:0.2rem;">₱{{ number_format($thisWeek, 2) }}</p>
        <p style="font-size:0.72rem; color:{{ $weekColor }}; font-weight:600;">
            <i class="bi {{ $weekIcon }}"></i> {{ $weekLabel }} vs last week
        </p>
    </div>

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Sales This Month</p>
        <p style="font-size:1.25rem; font-weight:700; color:#333; margin-bottom:0.2rem;">₱{{ number_format($thisMonth, 2) }}</p>
        <p style="font-size:0.72rem; color:{{ $monthColor }}; font-weight:600;">
            <i class="bi {{ $monthIcon }}"></i> {{ $monthLabel }} vs last month
        </p>
    </div>

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Recommendations</p>
        <p style="font-size:1.25rem; font-weight:700; color:#F4845F; margin-bottom:0.2rem;">{{ count($recommendations) }}</p>
        <p style="font-size:0.72rem; color:#666;">actionable insights</p>
    </div>
</div>

{{-- ══════════ RECOMMENDATIONS PANEL ══════════ --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.75rem;">
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
                    <p style="font-size:0.72rem; color:#555; line-height:1.4;">{{ $rec['message'] }}</p>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ══════════ BEST / LEAST SELLERS ══════════ --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1rem; margin-bottom:1rem;">
    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
            <i class="bi bi-star-fill" style="color:#F4845F;"></i> Best Sellers (last 30 days)
        </p>
        @if($bestSellers->isEmpty())
            <p style="font-size:0.78rem; color:#888;">No completed orders in this period yet.</p>
        @else
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
        @endif
    </div>

    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
            <i class="bi bi-graph-down" style="color:#888;"></i> Least Sellers (last 30 days)
        </p>
        @if($leastSellers->isEmpty())
            <p style="font-size:0.78rem; color:#888;">Not enough data to rank low-selling items.</p>
        @else
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
        @endif
    </div>
</div>

{{-- ══════════ INVENTORY STATUS ══════════ --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1rem; margin-bottom:1rem;">

    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#C0392B; margin-bottom:0.6rem;">
            <i class="bi bi-exclamation-octagon"></i> Out of Stock ({{ $outOfStock->count() }})
        </p>
        @if($outOfStock->isEmpty())
            <p style="font-size:0.78rem; color:#888;">No items are out of stock.</p>
        @else
            <ul style="margin:0; padding-left:1rem; font-size:0.78rem;">
                @foreach($outOfStock as $item)
                    <li style="margin-bottom:0.2rem;">
                        <strong>{{ $item->item_name }}</strong>
                        <span style="color:#888;">({{ $item->unit }})</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#B7791F; margin-bottom:0.6rem;">
            <i class="bi bi-exclamation-triangle"></i> Low Stock ({{ $lowStock->count() }})
        </p>
        @if($lowStock->isEmpty())
            <p style="font-size:0.78rem; color:#888;">No low-stock items.</p>
        @else
            <ul style="margin:0; padding-left:1rem; font-size:0.78rem;">
                @foreach($lowStock as $item)
                    <li style="margin-bottom:0.2rem;">
                        <strong>{{ $item->item_name }}</strong>
                        — {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->unit }}
                        <span style="color:#888;">(alert ≤ {{ rtrim(rtrim(number_format($item->low_stock_alert, 2), '0'), '.') }})</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#555; margin-bottom:0.6rem;">
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
    </div>
</div>

{{-- ══════════ LINKED INVENTORY (best seller ingredients) ══════════ --}}
@if($linkedToBest->isNotEmpty())
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
        <i class="bi bi-link-45deg"></i> Ingredients of Best-Selling Menu Items
    </p>
    <p style="font-size:0.75rem; color:#666; margin-bottom:0.5rem;">Monitor these closely — they feed your top sellers.</p>
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
</div>
@endif

{{-- ══════════ CHARTS ══════════ --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:1rem; margin-bottom:1rem;">

    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
            <i class="bi bi-graph-up"></i> Daily Sales Trend (last 14 days)
        </p>
        <div style="height:220px;">
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <div class="content-card">
        <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
            <i class="bi bi-pie-chart"></i> Sales by Category (last 30 days)
        </p>
        <div style="height:220px; display:flex; align-items:center; justify-content:center;">
            @if($salesByCategory->isEmpty())
                <p style="font-size:0.78rem; color:#888;">No category data yet.</p>
            @else
                <canvas id="categoryChart" style="max-height:210px;"></canvas>
            @endif
        </div>
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
    @endif
</div>
@endif

<p style="font-size:0.7rem; color:#888; text-align:center; margin-top:1rem;">
    Descriptive analytics &amp; rule-based recommendations. All thresholds are configurable in <code>AnalyticsService</code>.
</p>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // ── Daily Trend (line)
    (function () {
        const el = document.getElementById('trendChart');
        if (!el) return;
        new Chart(el.getContext('2d'), {
            type: 'line',
            data: {
                labels: {!! json_encode($dailyTrend['labels']) !!},
                datasets: [{
                    label: 'Sales (₱)',
                    data: {!! json_encode($dailyTrend['values']) !!},
                    borderColor: '#F4845F',
                    backgroundColor: 'rgba(244,132,95,0.12)',
                    borderWidth: 2,
                    tension: 0.35,
                    fill: true,
                    pointBackgroundColor: '#F4845F'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10, family: 'Poppins' } } },
                    y: { grid: { color: '#f0f0f0' }, ticks: { font: { size: 10, family: 'Poppins' } } }
                }
            }
        });
    })();

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

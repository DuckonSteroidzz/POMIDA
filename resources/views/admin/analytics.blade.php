@extends('admin.layout')

@section('title', 'Analytics - Peachy Admin')

@section('content')

@php
    $periodLabels = [
        'today' => 'Today',
        'last7' => 'Last 7 Days',
        'last30'=> 'Last 30 Days',
        'month' => 'This Month',
        'custom'=> 'Custom Range',
    ];
@endphp

<div class="an-toolbar">
    <p class="page-title" style="margin:0;">Analytics</p>
    <div class="no-print">
        <button type="button" onclick="printAnalytics();" class="btn-primary-custom" style="padding:0.5rem 1rem;font-size:0.78rem;">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<p style="font-size:0.78rem; color:#666; margin-bottom:1rem;">
    Viewing: <strong>{{ $branchName }}</strong>
    &nbsp;&bull;&nbsp; {{ $periodStart->format('M d, Y') }} &ndash; {{ $periodEnd->format('M d, Y') }}
</p>

{{-- ══════════ DATE RANGE FILTER ══════════ --}}
<div class="content-card no-print" style="margin-bottom:1rem;">
    <form method="GET" action="{{ route('admin.analytics') }}" id="analytics-filter-form"
          style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">

        <div>
            <label class="form-label-custom">Date Range</label>
            <select name="period" id="an-period-select" class="form-control-custom" style="margin-bottom:0;width:180px;"
                    onchange="document.getElementById('an-custom-range').style.display = (this.value === 'custom') ? 'flex' : 'none'; if (this.value !== 'custom') { this.form.submit(); }">
                @foreach($periodLabels as $key => $label)
                    <option value="{{ $key }}" {{ $period === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div id="an-custom-range" style="display:{{ $period === 'custom' ? 'flex' : 'none' }};gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label class="form-label-custom">Start Date</label>
                <input type="date" name="date_from" class="form-control-custom" value="{{ $dateFrom }}" style="margin-bottom:0;width:170px;">
            </div>
            <div>
                <label class="form-label-custom">End Date</label>
                <input type="date" name="date_to" class="form-control-custom" value="{{ $dateTo }}" style="margin-bottom:0;width:170px;">
            </div>
            <button type="submit" name="period" value="custom" class="btn-primary-custom" style="padding:0.55rem 1.1rem;">
                <i class="bi bi-search"></i> Apply
            </button>
        </div>
    </form>
</div>

{{-- ══════════ KPI ROW ══════════ --}}
<div class="an-kpi-grid" style="margin-bottom:1rem;">

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Total Sales</p>
        <p style="font-size:1.3rem; font-weight:700; color:#333;">₱{{ number_format($totalSales, 2) }}</p>
    </div>

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Total Orders</p>
        <p style="font-size:1.3rem; font-weight:700; color:#333;">{{ number_format($totalOrders) }}</p>
    </div>

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Average Order Value</p>
        <p style="font-size:1.3rem; font-weight:700; color:#333;">
            {{ is_null($averageOrderValue) ? '&mdash;' : '₱' . number_format($averageOrderValue, 2) }}
        </p>
    </div>

    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Average Rating</p>
        @if($averageRating !== null)
            <p style="font-size:1.3rem; font-weight:700; color:#333;">
                <i class="bi bi-star-fill" style="color:#F4845F;"></i> {{ number_format($averageRating, 2) }} / 5
            </p>
        @else
            <p style="font-size:1.3rem; font-weight:700; color:#ccc;">&mdash;</p>
        @endif
    </div>
</div>

{{-- ══════════ SALES PER DAY (bar chart) ══════════ --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
        <i class="bi bi-bar-chart"></i> Sales per Day
    </p>
    @if(empty($dailySales['labels']))
        <p style="font-size:0.78rem; color:#888;">No days in this range.</p>
    @else
        <div style="height:280px;">
            <canvas id="salesPerDayChart"></canvas>
        </div>
    @endif
</div>

{{-- ══════════ TOP 5 PRODUCTS ══════════ --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
        <i class="bi bi-star-fill" style="color:#F4845F;"></i> Top 5 Products (by quantity sold)
    </p>
    @if($topProducts->isEmpty())
        <p style="font-size:0.78rem; color:#888;">No completed orders in this period yet.</p>
    @else
        <div style="overflow-x:auto;">
        <table style="width:100%; font-size:0.78rem; border-collapse:collapse;">
            <thead>
                <tr style="background:#F4845F; color:white;">
                    <th style="padding:0.4rem; text-align:left;">#</th>
                    <th style="padding:0.4rem; text-align:left;">Item</th>
                    <th style="padding:0.4rem; text-align:right;">Qty Sold</th>
                    <th style="padding:0.4rem; text-align:right;">Revenue</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topProducts as $i => $row)
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:0.4rem;">{{ $i + 1 }}</td>
                        <td style="padding:0.4rem;">{{ optional($row->menuItem)->name ?? '(deleted item)' }}</td>
                        <td style="padding:0.4rem; text-align:right; font-weight:600; color:#F4845F;">{{ number_format($row->total_qty) }}</td>
                        <td style="padding:0.4rem; text-align:right;">₱{{ number_format($row->total_revenue, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

@endsection

@push('styles')
<style>
    .an-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-bottom: 0.5rem;
    }

    /* 2 columns up to tablet width, 4 in one row from desktop on — avoids the
       auto-fit dead zone (roughly 640-1050px content width) where exactly 3 of
       the 4 tiles fit per row and the last one is stranded alone with a wide
       empty gap beside it. */
    .an-kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }

    @media (min-width: 768px) {
        .an-kpi-grid { grid-template-columns: repeat(4, 1fr); }
    }
</style>
@endpush

@push('scripts')
<script src="/vendor/chart.umd.min.js"></script>
<script>
    (function () {
        const el = document.getElementById('salesPerDayChart');
        if (!el) return;

        new Chart(el.getContext('2d'), {
            type: 'bar',
            data: {
                labels: {!! json_encode($dailySales['labels']) !!},
                datasets: [{
                    label: 'Sales (₱)',
                    data: {!! json_encode($dailySales['values']) !!},
                    backgroundColor: '#F4845F',
                    borderRadius: 4
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

    function printAnalytics() {
        // Prints in-page via the shared printInFrame() helper (admin.layout) —
        // re-runs the current filters through the dedicated, unbounded print
        // view, but inside a hidden iframe instead of a new tab.
        printInFrame('{{ route('admin.analytics.print') }}' + window.location.search);
    }
</script>
@endpush

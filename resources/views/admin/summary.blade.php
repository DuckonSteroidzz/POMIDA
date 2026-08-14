@extends('admin.layout')

@section('title', 'Summary - Peachy Admin')

@section('content')

<div class="summary-toolbar" style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;flex-wrap:wrap;">
    <p class="page-title" style="margin:0;">Summary</p>
    <button type="button" onclick="window.print()" class="btn-primary-custom no-print"
        style="padding:0.5rem 1rem;font-size:0.78rem;">
        <i class="bi bi-printer"></i> Print Report
    </button>
</div>

{{-- ── Print-only Report Header ── --}}
<div class="print-only print-header" style="display:none;">
    <h2 style="margin:0 0 0.25rem;">Peachy Cakes &amp; Deli Cafe</h2>
    <p style="margin:0;font-size:0.95rem;font-weight:600;">Sales Summary Report</p>
    <p style="margin:0;font-size:0.85rem;">Branch: {{ $selectedBranchName ?? 'All Branches' }}</p>
    <p style="margin:0;font-size:0.85rem;">
        Date Range:
        @if(!empty($dateFrom) || !empty($dateTo))
            {{ $dateFrom ?? '—' }} to {{ $dateTo ?? '—' }}
        @else
            All Available Data
        @endif
    </p>
    <p style="margin:0;font-size:0.8rem;color:#555;">Generated: {{ now()->format('M d, Y g:i A') }}</p>
    <hr>
</div>

{{-- Date Filter --}}
<div class="content-card no-print">
    <form method="GET" action="{{ route('admin.summary') }}" style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
        <div>
            <label class="form-label-custom">Date From</label>
            <input type="date" name="date_from" class="form-control-custom" value="{{ request('date_from') }}" style="margin-bottom: 0; width: 180px;">
        </div>
        <div>
            <label class="form-label-custom">Date To</label>
            <input type="date" name="date_to" class="form-control-custom" value="{{ request('date_to') }}" style="margin-bottom: 0; width: 180px;">
        </div>
        <button type="submit" class="btn-primary-custom" style="padding: 0.55rem 1.25rem;">
            <i class="bi bi-search"></i> Filter
        </button>
    </form>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">

    {{-- ══════════ Left Column ══════════ --}}
    <div>

        {{-- Total Orders Today --}}
        <div class="content-card" style="margin-bottom: 1rem;">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.6rem; text-align: center; text-transform: uppercase;">
                Total Orders Today
            </p>
            <p style="font-size: 1.5rem; font-weight: 700; color: #333; text-align: center;">
                {{ $totalOrdersToday ?? 0 }}
            </p>
        </div>

        {{-- Most Ordered Item --}}
        <div class="content-card" style="margin-bottom: 1rem;">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.6rem; text-align: center; text-transform: uppercase;">
                Most Ordered Item
            </p>
            <p style="font-size: 0.9rem; font-weight: 600; color: #333; text-align: center;">
                {{ $mostOrderedItem ?? '-' }}
            </p>
        </div>

        {{-- Total Orders --}}
        <div class="content-card" style="margin-bottom: 1rem;">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.6rem; text-align: center; text-transform: uppercase;">
                Total Orders
            </p>
            <p style="font-size: 1.2rem; font-weight: 700; color: #333; text-align: center;">
                {{ $totalOrders ?? 0 }}
            </p>
        </div>

        {{-- Least Ordered Item --}}
        <div class="content-card" style="margin-bottom: 1rem;">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.6rem; text-align: center; text-transform: uppercase;">
                Least Ordered Item
            </p>
            <p style="font-size: 0.9rem; font-weight: 600; color: #333; text-align: center;">
                {{ $leastOrderedItem ?? '-' }}
            </p>
        </div>

        {{-- Total Revenue --}}
        <div class="content-card" style="margin-bottom: 1rem;">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.6rem; text-align: center; text-transform: uppercase;">
                Total Revenue
            </p>
            <p style="font-size: 1.3rem; font-weight: 700; color: #333; text-align: center;">
                ₱{{ number_format($totalRevenue ?? 0, 2) }}
            </p>
        </div>

        {{-- Peak Hours --}}
        <div class="content-card" style="margin-bottom: 1rem;">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.6rem; text-align: center; text-transform: uppercase;">
                Peak Hours
            </p>
            <p style="font-size: 0.9rem; font-weight: 600; color: #333; text-align: center;">
                {{ $peakHours ?? 'No data yet' }}
            </p>
        </div>

        {{-- Export CSV --}}
        <div class="content-card">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.6rem; text-align: center; text-transform: uppercase;">
                Export Data
            </p>
            <div style="display:flex;gap:0.5rem;justify-content:center;">
                <a href="{{ route('admin.export.orders') }}?date_from={{ request('date_from') }}&date_to={{ request('date_to') }}" class="btn-primary-custom" style="padding:0.5rem 1rem;font-size:0.78rem;text-decoration:none;">
                    <i class="bi bi-download"></i> Export Orders CSV
                </a>
            </div>
        </div>

    </div>

    {{-- ══════════ Right Column ══════════ --}}
    <div>

        {{-- Line Chart --}}
        <div class="content-card" style="margin-bottom: 1rem;">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.75rem; text-align: center; text-transform: uppercase;">
                Sales (Last 7 Days)
            </p>
            <div style="height: 180px; position: relative;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

        {{-- Pie Chart --}}
        <div class="content-card" style="margin-bottom: 1rem;">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.75rem; text-align: center; text-transform: uppercase;">
                Sales by Category
            </p>
            <div style="height: 180px; display: flex; align-items: center; justify-content: center;">
                <canvas id="pieChart" style="max-height: 170px;"></canvas>
            </div>
        </div>

        {{-- Business Insights --}}
        <div class="content-card">
            <p style="font-size: 0.72rem; font-weight: 600; color: white; background: #F4845F; padding: 0.4rem 0.75rem; border-radius: 6px; margin-bottom: 0.75rem; text-align: center; text-transform: uppercase;">
                Business Insights
            </p>
            <p style="font-size: 0.78rem; color: #555; line-height: 1.6;">
                {{ $aiRecommendation ?? 'Add more completed orders to generate analytics-based business insights from your sales data.' }}
            </p>
            <a href="{{ route('admin.analytics') }}" style="display:inline-block;margin-top:0.5rem;font-size:0.75rem;color:#F4845F;font-weight:600;text-decoration:none;">
                <i class="bi bi-graph-up-arrow"></i> View full Analytics &rarr;
            </a>
        </div>

    </div>

</div>

@endsection

@push('styles')
<style>
    /* Hidden on screen, shown only when printing */
    .print-only { display: none !important; }

    @media print {
        /* Hide all admin chrome (sidebar, hamburger, branch selector, etc.) */
        .sidebar,
        .sidebar-overlay,
        .hamburger-btn,
        .no-print {
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

        .summary-toolbar .page-title { font-size: 1rem; }

        /* Reveal the report header */
        .print-only { display: block !important; }
        .print-header h2 { font-size: 1.3rem; font-weight: 700; }

        /* Compact cards for paper */
        .content-card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            page-break-inside: avoid;
            margin-bottom: 0.5rem !important;
            padding: 0.5rem !important;
        }

        /* Keep two-column grid but tighter */
        canvas { max-height: 160px !important; }

        a[href]::after { content: ''; }
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Line Chart
    const salesCtx = document.getElementById('salesChart').getContext('2d');
    new Chart(salesCtx, {
        type: 'line',
        data: {
            labels: {!! json_encode($chartLabels ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']) !!},
            datasets: [{
                label: 'Sales (₱)',
                
                    data: {!! json_encode($chartData ?? [0, 0, 0, 0, 0, 0, 0]) !!},
                
                borderColor: '#F4845F',
                backgroundColor: 'rgba(244,132,95,0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#F4845F',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 10,
                            family: 'Poppins'
                        }
                    }
                },
                y: {
                    grid: {
                        color: '#f0f0f0'
                    },
                    ticks: {
                        font: {
                            size: 10,
                            family: 'Poppins'
                        }
                    }
                }
            }
        }
    });

    // Pie Chart
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels: {!! json_encode($pieLabels ?? ['No data']) !!},
            datasets: [{
                data: {!! json_encode($pieData ?? [1]) !!},
                backgroundColor: ['#F4845F', '#C0392B', '#F4A460', '#FFB6C1', '#DEB887', '#87CEEB', '#98D8C8'],
                borderWidth: 2,
                borderColor: 'white'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        font: {
                            size: 10,
                            family: 'Poppins'
                        },
                        padding: 10
                    }
                }
            }
        }
    });
</script>
@endpush
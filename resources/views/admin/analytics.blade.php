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
    <div class="no-print an-toolbar-actions">
        {{-- Export CSV (Phase 2d). A plain link, not a form: the export is a
             GET of the same filters this page was rendered with, so the two
             querystrings are built from the same variables and the file can
             only ever describe the period and branch on screen. --}}
        <a href="{{ route('admin.analytics.export', array_filter([
                'period'    => $period,
                'date_from' => $period === 'custom' ? $dateFrom : null,
                'date_to'   => $period === 'custom' ? $dateTo : null,
           ])) }}"
           class="btn-edit-custom an-export-btn">
            <i class="bi bi-filetype-csv"></i> Export CSV
        </a>
        <button type="button" onclick="printAnalytics();" class="btn-primary-custom" style="padding:0.5rem 1rem;font-size:0.78rem;">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<p style="font-size:0.78rem; color:#666; margin-bottom:1rem;">
    Viewing: <strong>{{ $branchName }}</strong>
    &nbsp;&bull;&nbsp; {{ $periodStart->format('M d, Y') }} &ndash; {{ $periodEnd->format('M d, Y') }}
</p>

{{-- Silent unless showAnalytics() had to correct or discard the submitted
     custom range. Sits directly under the "Viewing:" line so the explanation
     is next to the period it explains. --}}
@include('admin.partials.date-range-notice')

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

    {{-- GROSS PROFIT (Phase 2d). Straight from ProfitCalculationService — the
         one source of money in this project — so this tile and the Summary
         screen's own Gross Profit are the same calculation, net of discounts,
         and cannot drift apart. --}}
    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Gross Profit</p>
        <p style="font-size:1.3rem; font-weight:700; color:#333;">₱{{ number_format($intel['financials']['gross_profit'], 2) }}</p>
        <p style="font-size:0.68rem; color:#999; margin:0.15rem 0 0;">
            @if($intel['financials']['margin_percent'] === null)
                No net revenue in this period
            @else
                {{ number_format($intel['financials']['margin_percent'], 1) }}% margin of net revenue
            @endif
        </p>
    </div>

    {{-- INVENTORY RISK (Phase 2d). Counts the two risk states that describe a
         problem with stock RIGHT NOW — Out of Stock and Critical — and
         deliberately NOT the two that describe missing data. An item nothing
         is known about is not evidence of a risk, and counting it as one would
         inflate this number every quiet week. A live snapshot like the
         capacity table below it, not a figure for the selected period. --}}
    <div class="content-card">
        <p style="font-size:0.7rem; font-weight:600; color:#888; text-transform:uppercase; margin-bottom:0.3rem;">Inventory Risk</p>
        <p style="font-size:1.3rem; font-weight:700; color:{{ $intel['summary']['items_requiring_attention'] > 0 ? '#b3261e' : '#2e7d4f' }};">
            {{ number_format($intel['summary']['items_requiring_attention']) }}
        </p>
        <p style="font-size:0.68rem; color:#999; margin:0.15rem 0 0;">
            @if($intel['summary']['items_requiring_attention'] > 0)
                item{{ $intel['summary']['items_requiring_attention'] === 1 ? '' : 's' }} requiring attention
            @else
                no items requiring attention
            @endif
        </p>
    </div>
</div>

{{-- ══════════ SALES TREND & FORECAST ══════════

     THE page's one main graph, and it stays the only one. Historical actuals
     are a solid line; the moving-average projection continues it as a dashed
     line beginning the day after the selected range ends, so the eye can tell
     measured from estimated without reading the legend.

     The Projected KPI lives HERE rather than in the KPI row above, and that is
     deliberate: the four tiles above are all things that happened, and dropping
     an estimate among them is how a projection quietly gets read as a fact. It
     is styled to match its own dashed line instead.                        --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.6rem;">
        <i class="bi bi-graph-up"></i> Sales Trend &amp; Forecast
    </p>

    <div class="fc-head">
        {{-- Projected Next 7 Days. Never "₱0" when the data cannot support a
             figure — a peso amount would claim demand is expected to be
             nothing, which is a different statement from "not enough history
             to say". --}}
        <div class="fc-kpi">
            <p class="fc-kpi-label">Projected Next 7 Days</p>
            @if($forecast['sufficient'])
                <p class="fc-kpi-value">₱{{ number_format($forecast['total_next_7_days'], 2) }}</p>
                <p class="fc-kpi-sub">
                    Estimated &bull; about ₱{{ number_format($forecast['next_day'], 2) }}/day
                </p>
            @else
                <p class="fc-kpi-value fc-kpi-none">Insufficient Data</p>
                <p class="fc-kpi-sub">Not enough history to project</p>
            @endif
        </div>

        <div class="fc-periods">
            <p class="fc-period">
                <span class="fc-swatch fc-swatch-actual"></span>
                <strong>Historical:</strong>
                {{ $periodStart->format('M d') }} &ndash; {{ $periodEnd->format('M d, Y') }}
            </p>
            @if($forecast['sufficient'])
                <p class="fc-period">
                    <span class="fc-swatch fc-swatch-forecast"></span>
                    <strong>Forecast:</strong>
                    {{ \Carbon\Carbon::parse($forecast['forecast_start'])->format('M d') }}
                    &ndash;
                    {{ \Carbon\Carbon::parse($forecast['forecast_end'])->format('M d, Y') }}
                </p>
            @endif
        </div>
    </div>

    @if(empty($dailySales['labels']))
        <p style="font-size:0.78rem; color:#888;">No days in this range.</p>
    @else
        <div style="height:280px;">
            <canvas id="salesTrendChart"></canvas>
        </div>
    @endif

    @if($forecast['sufficient'])
        <p class="fc-note">
            Forecast method: moving average of the last {{ $forecast['observations_used'] }}
            day{{ $forecast['observations_used'] === 1 ? '' : 's' }} of sales in the selected
            period. A projection from past sales, not a guaranteed figure.
        </p>
    @else
        <p class="fc-note fc-note-warn">
            <i class="bi bi-info-circle"></i> {{ $forecast['message'] }}
            Sales were recorded on {{ $forecast['days_with_sales'] }} of
            {{ $forecast['historical_days'] }} day{{ $forecast['historical_days'] === 1 ? '' : 's' }}
            in this period.
        </p>
    @endif
</div>

{{-- ══════════ MENU PERFORMANCE ══════════

     Phase 2d. This card replaces the old "Top 5 Products (by quantity sold)"
     widget, and the replacement is the point rather than a side effect.

     That widget aggregated its own revenue out of order_items.subtotal while
     the Summary screen reported revenue net of discounts — the Phase 1 finding
     that two screens in this app quoted two different revenues for the same
     sales. Every money column here comes from ProfitCalculationService, the
     one place in the project that is allowed to compute money, so this table,
     the Summary screen, the printed report and the CSV export are four
     renderings of one calculation.

     WHY "before order discounts" IS ON THE REVENUE HEADING. A discount belongs
     to the ORDER, and the database records no allocation of it to individual
     lines — there is no honest way to say which item a ₱50 senior discount
     came off. Per-item revenue is therefore necessarily the pre-discount
     figure, and the column says so rather than letting it be read as takings.
     The discounted total is reported once, where it is meaningful: the period
     Gross Profit tile above and the CSV's summary block.                  --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.25rem;">
        <i class="bi bi-star-fill" style="color:#F4845F;"></i> Menu Performance
    </p>
    <p style="font-size:0.7rem; color:#888; margin-bottom:0.6rem;">
        Top {{ count($performanceRows) }} by revenue for the selected period. Revenue is the menu price
        before order-level discounts, which belong to the order rather than to any one item; COGS is the
        ingredient cost recorded at the time of sale.
    </p>
    @if(empty($performanceRows))
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
                    <th style="padding:0.4rem; text-align:right;">COGS</th>
                    <th style="padding:0.4rem; text-align:right;">Gross Profit</th>
                    <th style="padding:0.4rem; text-align:right;">Margin</th>
                </tr>
            </thead>
            <tbody>
                @foreach($performanceRows as $i => $row)
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:0.4rem;">{{ $i + 1 }}</td>
                        <td style="padding:0.4rem;">
                            {{ $row['menu_item_name'] }}
                            {{-- Only under All Branches, where two branches may
                                 legitimately carry the same item name. --}}
                            @if($isAllBranches && $row['branch_name'])
                                <span class="mpc-branch">{{ $row['branch_name'] }}</span>
                            @endif
                        </td>
                        <td style="padding:0.4rem; text-align:right; font-weight:600; color:#F4845F;">{{ number_format($row['quantity_sold']) }}</td>
                        <td style="padding:0.4rem; text-align:right;">₱{{ number_format($row['revenue'], 2) }}</td>
                        <td style="padding:0.4rem; text-align:right;">₱{{ number_format($row['cogs'], 2) }}</td>
                        <td style="padding:0.4rem; text-align:right;">₱{{ number_format($row['gross_profit'], 2) }}</td>
                        <td style="padding:0.4rem; text-align:right;">
                            @if($row['margin_percent'] === null)
                                <span style="color:#999;">&mdash;</span>
                            @else
                                {{ number_format($row['margin_percent'], 1) }}%
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

{{-- ══════════ DEMAND BY DAY OF WEEK ══════════

     DESCRIPTIVE, not seasonal. Every line here is phrased "historical X has
     averaged…" and never "X will be busier": with the handful of observations
     per weekday this dataset holds, a gap between two weekdays is as likely to
     be which weeks happened to be busy as it is to be anything about the
     weekday. The observation count sits in its own column so the owner can see
     how thin the evidence is rather than being asked to trust the average.

     Only weekdays the selected period actually contains are listed — no row is
     invented for a weekday that never occurred.                            --}}
@if(!empty($weekdayDemand))
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.25rem;">
        <i class="bi bi-calendar-week" style="color:#F4845F;"></i> Historical Demand by Day of Week
    </p>
    <p style="font-size:0.7rem; color:#888; margin-bottom:0.6rem;">
        Average takings per weekday across the selected period, counting every occurrence of
        that weekday &mdash; including days with no sales. Descriptive history, not a prediction:
        a small number of observations cannot establish a weekly pattern on its own.
    </p>

    <div style="overflow-x:auto;">
    <table class="fc-table" style="width:100%; font-size:0.78rem; border-collapse:collapse;">
        <thead>
            <tr style="background:#F4845F; color:white;">
                <th style="padding:0.4rem; text-align:left;">Day</th>
                <th style="padding:0.4rem; text-align:right;">Historical Average</th>
                <th style="padding:0.4rem; text-align:right;">Days Observed</th>
                <th style="padding:0.4rem; text-align:right;">Days with Sales</th>
            </tr>
        </thead>
        <tbody>
            @foreach($weekdayDemand as $row)
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:0.4rem;">{{ $row['day'] }}</td>
                    <td style="padding:0.4rem; text-align:right; font-weight:600; color:#F4845F;">
                        ₱{{ number_format($row['average'], 2) }}
                    </td>
                    <td style="padding:0.4rem; text-align:right;">{{ $row['observations'] }}</td>
                    <td style="padding:0.4rem; text-align:right;">{{ $row['days_with_sales'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</div>
@endif

{{-- ══════════ MENU DEMAND FORECAST ══════════

     Per-item projection in UNITS per day, for the items that actually have
     enough selling days behind them. Items that sold nothing in the period are
     not listed at all: a zero row would assert there is no demand for them,
     when the truth may simply be that they are new, seasonal, or were added to
     the menu last week. Items that sold but not often enough are listed and
     told as much, because for those "not enough yet" is the honest answer
     rather than silence.                                                   --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.25rem;">
        <i class="bi bi-basket" style="color:#F4845F;"></i> Menu Demand Forecast
    </p>
    <p style="font-size:0.7rem; color:#888; margin-bottom:0.6rem;">
        Projected units per item for the next {{ $menuForecast['horizon_days'] }} days, from the
        moving average of the last {{ $menuForecast['window_days'] }} days of the selected period.
        An item needs sales on at least {{ $menuForecast['min_days_with_sales'] }} days before a
        forecast is shown for it. Estimates, not commitments.
    </p>

    @if(empty($menuForecast['rows']))
        <p style="font-size:0.78rem; color:#888;">
            No menu items sold in this period, so there is nothing to forecast from.
        </p>
    @else
        <div style="overflow-x:auto;">
        <table class="fc-table" style="width:100%; font-size:0.78rem; border-collapse:collapse;">
            <thead>
                <tr style="background:#F4845F; color:white;">
                    <th style="padding:0.4rem; text-align:left;">Item</th>
                    <th style="padding:0.4rem; text-align:right;">Projected / Day</th>
                    <th style="padding:0.4rem; text-align:right;">Projected Next {{ $menuForecast['horizon_days'] }} Days</th>
                    <th style="padding:0.4rem; text-align:right;">Days with Sales</th>
                </tr>
            </thead>
            <tbody>
                @foreach($menuForecast['rows'] as $row)
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:0.4rem;">{{ $row['menu_item_name'] }}</td>
                        @if($row['sufficient'])
                            <td style="padding:0.4rem; text-align:right; font-weight:600; color:#F4845F;">
                                {{ number_format($row['forecast_qty_per_day'], 2) }}
                            </td>
                            <td style="padding:0.4rem; text-align:right; font-weight:600; color:#F4845F;">
                                {{ number_format($row['forecast_qty_next_7_days'], 1) }}
                            </td>
                        @else
                            {{-- One spanned cell carrying the reason, rather than
                                 two em-dashes the reader has to interpret. --}}
                            <td colspan="2" style="padding:0.4rem; text-align:right; color:#888;">
                                {{ $row['message'] }}
                            </td>
                        @endif
                        <td style="padding:0.4rem; text-align:right;">{{ $row['days_with_sales'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        <p style="font-size:0.7rem; color:#888; margin-top:0.5rem;">
            {{ $menuForecast['items_forecastable'] }} of {{ $menuForecast['items_with_history'] }}
            item{{ $menuForecast['items_with_history'] === 1 ? '' : 's' }} sold in this period have
            enough history to forecast.
        </p>
    @endif
</div>

{{-- ══════════ MENU PRODUCTION CAPACITY ══════════

     Deterministic, not predictive: floor(available ÷ required per order) for
     every recipe ingredient, and the menu item can be made as many times as
     its TIGHTEST ingredient allows. The same arithmetic the checkout stock
     gate runs (InventoryDeductionService::availabilityBreakdownFor()), so a
     capacity of 5 here and "only 5 left in stock" at the till are the same
     sentence.

     Live snapshot. The date filter at the top of this page does not move these
     numbers and is not meant to — "how many more can we make" is a question
     about the shelf right now. The caption below says so on the page itself
     rather than leaving the owner to notice it.                            --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.25rem;">
        <i class="bi bi-speedometer2" style="color:#F4845F;"></i> Menu Production Capacity
    </p>
    <p style="font-size:0.7rem; color:#888; margin-bottom:0.6rem;">
        How many more orders of each item current stock can cover, after what pending orders
        have already claimed. A live snapshot &mdash; not affected by the date range above.
        Counts the base recipe only; add-on options are chosen per order, so they are not
        included in an item's standing capacity.
        <br>
        {{-- The one sentence that keeps the three time bases on this row apart.
             Capacity is now; forecast comes from the selected period and points
             at the next 7 days; the risk verdict compares the two. --}}
        <strong>Forecasted Demand</strong> and <strong>Potential Shortage</strong> are projections for the
        next {{ $intel['horizon_days'] }} days, estimated from the selected period &mdash; not measurements.
        Where a figure cannot be calculated it says so; it is never shown as zero.
    </p>

    @if(empty($intel['capacity_rows']))
        <p style="font-size:0.78rem; color:#888;">No available menu items in this branch yet.</p>
    @else
        <div style="overflow-x:auto;">
        <table class="mpc-table" style="width:100%; font-size:0.78rem; border-collapse:collapse;">
            <thead>
                <tr style="background:#F4845F; color:white;">
                    <th style="padding:0.4rem; text-align:left;">Menu</th>
                    <th style="padding:0.4rem; text-align:right;">Current Capacity</th>
                    <th style="padding:0.4rem; text-align:right;">Forecasted Demand</th>
                    <th style="padding:0.4rem; text-align:right;">Potential Shortage</th>
                    <th style="padding:0.4rem; text-align:right;">Coverage</th>
                    <th style="padding:0.4rem; text-align:left;">Bottleneck</th>
                    <th style="padding:0.4rem; text-align:left;">Risk</th>
                </tr>
            </thead>
            <tbody>
                @foreach($intel['capacity_rows'] as $row)
                    @php
                        // Phase 2d: the old three-way Status badge (No Recipe Set /
                        // Out of Stock / Low Stock / In Stock) is GONE from this
                        // table, replaced by the Risk column, which is a strict
                        // superset of it — Unavailable and Out of Stock carry the
                        // same two facts, and Low/Critical/Good/Insufficient Data
                        // say more than "In Stock" ever did. Two columns for one
                        // question is exactly the overcrowding that invites the
                        // reader to look for a contradiction between them. The
                        // printed sheet keeps its own Capacity Status column, which
                        // is the Phase 2b.1 section and is not redesigned here.
                        $riskClass = [
                            \App\Services\AnalyticsIntelligenceService::RISK_GOOD              => 'mpc-badge-ok',
                            \App\Services\AnalyticsIntelligenceService::RISK_LOW               => 'mpc-badge-low',
                            \App\Services\AnalyticsIntelligenceService::RISK_CRITICAL          => 'mpc-badge-out',
                            \App\Services\AnalyticsIntelligenceService::RISK_OUT_OF_STOCK      => 'mpc-badge-out',
                            \App\Services\AnalyticsIntelligenceService::RISK_INSUFFICIENT_DATA => 'mpc-badge-none',
                            \App\Services\AnalyticsIntelligenceService::RISK_UNAVAILABLE       => 'mpc-badge-none',
                        ][$row['risk']] ?? 'mpc-badge-none';

                        $detailId = 'mpc-detail-' . $row['menu_item_id'];
                    @endphp

                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:0.4rem;">
                            <button type="button" class="mpc-toggle" aria-expanded="false"
                                    aria-controls="{{ $detailId }}"
                                    onclick="mpcToggle(this, '{{ $detailId }}');">
                                <i class="bi bi-chevron-right mpc-caret"></i>
                                <span>{{ $row['menu_item_name'] }}</span>
                            </button>
                            {{-- Only under "All Branches", where the same item name can
                                 legitimately appear once per branch and the row would
                                 otherwise be ambiguous. A locked supervisor has one
                                 branch and never needs telling which. --}}
                            @if($isAllBranches && $row['branch_name'])
                                <span class="mpc-branch">{{ $row['branch_name'] }}</span>
                            @endif
                        </td>
                        <td style="padding:0.4rem; text-align:right; font-weight:600; color:#F4845F;">
                            @if($row['is_measurable'])
                                {{ number_format($row['capacity']) }}
                            @else
                                &mdash;
                            @endif
                        </td>

                        {{-- Forecasted demand over the horizon. "Insufficient Data",
                             never 0 — the two mean opposite things. --}}
                        <td style="padding:0.4rem; text-align:right;">
                            @if($row['forecast_sufficient'])
                                {{ number_format($row['forecast_qty_next_7_days'], 1) }}
                            @else
                                <span class="mpc-na">Insufficient Data</span>
                            @endif
                        </td>

                        {{-- Shortage only when one EXISTS. Capacity at or above the
                             projection is "None", which is a different statement
                             from a shortage of zero orders. --}}
                        <td style="padding:0.4rem; text-align:right;">
                            @if(!$row['is_measurable'])
                                <span class="mpc-na">Unavailable</span>
                            @elseif(!$row['forecast_sufficient'])
                                <span class="mpc-na">Insufficient Data</span>
                            @elseif($row['has_shortage'])
                                <strong style="color:#b3261e;">{{ number_format($row['potential_shortage'], 1) }}</strong>
                            @else
                                <span class="mpc-na">None</span>
                            @endif
                        </td>

                        {{-- Days of coverage = capacity ÷ average daily demand.
                             Never divided when demand is unknown or zero. --}}
                        <td style="padding:0.4rem; text-align:right;">
                            @if($row['coverage_days'] !== null)
                                {{ number_format($row['coverage_days'], 1) }} day{{ abs($row['coverage_days'] - 1.0) < 0.05 ? '' : 's' }}
                            @elseif($row['coverage_reason'] === \App\Services\AnalyticsIntelligenceService::COVERAGE_NO_DEMAND)
                                <span class="mpc-na">No Projected Demand</span>
                            @elseif($row['coverage_reason'] === \App\Services\AnalyticsIntelligenceService::COVERAGE_UNAVAILABLE)
                                <span class="mpc-na">Unavailable</span>
                            @else
                                <span class="mpc-na">Insufficient Data</span>
                            @endif
                        </td>

                        <td style="padding:0.4rem;">
                            {{ $row['is_measurable'] && $row['bottleneck_name'] ? $row['bottleneck_name'] : '—' }}
                        </td>
                        <td style="padding:0.4rem;">
                            <span class="mpc-badge {{ $riskClass }}" title="{{ $row['risk_reason'] }}">{{ $row['risk_label'] }}</span>
                        </td>
                    </tr>

                    <tr id="{{ $detailId }}" class="mpc-detail" hidden>
                        <td colspan="7" style="padding:0.5rem 0.4rem 0.9rem 1.4rem;">
                            <p style="font-size:0.74rem; color:#555; margin:0 0 0.45rem;">
                                <strong>Risk &mdash; {{ $row['risk_label'] }}:</strong> {{ $row['risk_reason'] }}
                            </p>
                            @if(!$row['is_measurable'])
                                <p style="font-size:0.74rem; color:#888; margin:0;">
                                    @if($row['unavailable_reason'] === \App\Services\ProductionCapacityService::REASON_CROSS_BRANCH_INGREDIENT)
                                        Production capacity unavailable because this menu item&rsquo;s recipe
                                        uses an ingredient from another branch. Stock is never shared between
                                        branches &mdash; correct the recipe on the Menu Items page.
                                    @elseif($row['unavailable_reason'] === \App\Services\ProductionCapacityService::REASON_NO_MEASURABLE_INGREDIENT)
                                        Production capacity unavailable because this menu item&rsquo;s recipe
                                        has no ingredient that can be measured against stock.
                                    @else
                                        Production capacity unavailable because this menu item has no recipe.
                                    @endif
                                </p>
                            @else
                                <p style="font-size:0.74rem; color:#555; margin:0 0 0.45rem;">
                                    Current production capacity:
                                    <strong>{{ number_format($row['capacity']) }}</strong>
                                    additional {{ $row['capacity'] === 1 ? 'order' : 'orders' }}.
                                    @if($row['bottleneck_name'])
                                        Bottleneck: <strong>{{ $row['bottleneck_name'] }}</strong>.
                                    @endif
                                </p>
                                <div style="overflow-x:auto;">
                                <table class="mpc-ing-table" style="width:100%; font-size:0.72rem; border-collapse:collapse;">
                                    <thead>
                                        <tr style="color:#888; text-align:left;">
                                            <th style="padding:0.25rem 0.4rem;">Ingredient</th>
                                            <th style="padding:0.25rem 0.4rem; text-align:right;">Required per order</th>
                                            <th style="padding:0.25rem 0.4rem; text-align:right;">Available</th>
                                            <th style="padding:0.25rem 0.4rem; text-align:right;">Capacity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($row['ingredients'] as $ing)
                                            @php
                                                // Trailing zeros trimmed the same way
                                                // InventoryDeductionService phrases quantities,
                                                // so "80" never renders as "80.000" on one screen
                                                // and "80" on another.
                                                $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
                                                $isBottleneck = $row['bottleneck_inventory_id'] === $ing['inventory_id'];
                                            @endphp
                                            <tr style="border-top:1px solid #f4f4f4;">
                                                <td style="padding:0.25rem 0.4rem;">
                                                    {{ $ing['name'] ?? 'Ingredient #' . $ing['inventory_id'] }}
                                                    @if($isBottleneck)
                                                        <span class="mpc-badge mpc-badge-low">Bottleneck</span>
                                                    @endif
                                                </td>
                                                <td style="padding:0.25rem 0.4rem; text-align:right;">
                                                    {{ $fmt($ing['required_per_unit']) }} {{ $ing['unit'] }}
                                                </td>
                                                <td style="padding:0.25rem 0.4rem; text-align:right;">
                                                    @if($ing['available'] === null)
                                                        &mdash;
                                                    @else
                                                        {{ $fmt($ing['available']) }} {{ $ing['unit'] }}
                                                    @endif
                                                </td>
                                                <td style="padding:0.25rem 0.4rem; text-align:right; font-weight:600;">
                                                    @if($ing['capacity'] === null)
                                                        @if($ing['is_missing'])
                                                            <span style="font-weight:400; color:#888;">Not tracked</span>
                                                        @else
                                                            &mdash;
                                                        @endif
                                                    @else
                                                        {{ number_format($ing['capacity']) }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

{{-- ══════════ INVENTORY INTELLIGENCE ══════════

     Phase 2d. The reverse of the recipe relationship: for each ingredient,
     what is expected to be consumed per day, how long the shelf covers that,
     and which menu items depend on it. Derived entirely from the capacity
     walk above — no additional query, and no possibility of the two
     disagreeing about which ingredients exist or how much of each is free.

     A LIVE SNAPSHOT of stock, measured against a projection FROM the selected
     period. Both halves are labelled, because they are different points in
     time and a reader who assumes otherwise will misread every row.        --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.25rem;">
        <i class="bi bi-box-seam" style="color:#F4845F;"></i> Inventory Intelligence
    </p>
    <p style="font-size:0.7rem; color:#888; margin-bottom:0.6rem;">
        Available stock right now against projected daily usage &mdash; that is, each menu item's
        forecast demand multiplied by what its recipe requires per order, summed over the items that
        use the ingredient. Where only some of those items have enough sales history to forecast, the
        usage figure covers only those and is marked <em>partial</em>, so it reads as a floor rather
        than as the whole picture.
    </p>

    @if(empty($intel['ingredients']))
        <p style="font-size:0.78rem; color:#888;">
            No recipe ingredients are tracked for this branch yet.
        </p>
    @else
        <div style="overflow-x:auto;">
        <table class="mpc-table" style="width:100%; font-size:0.78rem; border-collapse:collapse;">
            <thead>
                <tr style="background:#F4845F; color:white;">
                    <th style="padding:0.4rem; text-align:left;">Ingredient</th>
                    <th style="padding:0.4rem; text-align:right;">Available</th>
                    <th style="padding:0.4rem; text-align:right;">Projected Daily Usage</th>
                    <th style="padding:0.4rem; text-align:right;">Days of Stock</th>
                    <th style="padding:0.4rem; text-align:left;">Affected Menu Items</th>
                </tr>
            </thead>
            <tbody>
                @foreach($intel['ingredients'] as $ing)
                    @php
                        $fmtQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
                        $ingDetailId = 'inv-detail-' . $ing['inventory_id'];
                    @endphp
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:0.4rem;">
                            <button type="button" class="mpc-toggle" aria-expanded="false"
                                    aria-controls="{{ $ingDetailId }}"
                                    onclick="mpcToggle(this, '{{ $ingDetailId }}');">
                                <i class="bi bi-chevron-right mpc-caret"></i>
                                <span>{{ $ing['name'] ?? 'Ingredient #' . $ing['inventory_id'] }}</span>
                            </button>
                            @if($isAllBranches && $ing['branch_name'])
                                <span class="mpc-branch">{{ $ing['branch_name'] }}</span>
                            @endif
                        </td>
                        <td style="padding:0.4rem; text-align:right; font-weight:600;
                                   color:{{ $ing['is_out_of_stock'] ? '#b3261e' : '#F4845F' }};">
                            @if($ing['available'] === null)
                                <span class="mpc-na">Not tracked</span>
                            @else
                                {{ $fmtQty($ing['available']) }} {{ $ing['unit'] }}
                            @endif
                        </td>
                        <td style="padding:0.4rem; text-align:right;">
                            @if($ing['average_daily_usage'] === null)
                                <span class="mpc-na">Insufficient Data</span>
                            @else
                                {{ $fmtQty($ing['average_daily_usage']) }} {{ $ing['unit'] }}
                                @if($ing['usage_is_partial'])
                                    <span class="mpc-na" style="display:block;">partial</span>
                                @endif
                            @endif
                        </td>
                        <td style="padding:0.4rem; text-align:right;">
                            @if($ing['days_of_stock'] === null)
                                <span class="mpc-na">
                                    {{ $ing['days_of_stock_reason'] === \App\Services\AnalyticsIntelligenceService::COVERAGE_UNAVAILABLE
                                        ? 'Unavailable' : 'Insufficient Data' }}
                                </span>
                            @else
                                <strong style="color:{{ $ing['days_of_stock'] < $intel['coverage_critical_days'] ? '#b3261e' : '#333' }};">
                                    {{ number_format($ing['days_of_stock'], 1) }}
                                </strong>
                            @endif
                        </td>
                        <td style="padding:0.4rem;">
                            {{ $ing['menu_items_affected'] }}
                            @if($ing['bottleneck_for'] > 0)
                                <span class="mpc-badge mpc-badge-low">Limiting {{ $ing['bottleneck_for'] }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr id="{{ $ingDetailId }}" class="mpc-detail" hidden>
                        <td colspan="5" style="padding:0.5rem 0.4rem 0.9rem 1.4rem;">
                            {{-- The actual recipe relationship, item by item. Not a
                                 guess and not a count of items that merely look
                                 related: these are the menu items whose recipes name
                                 this inventory row. --}}
                            <p style="font-size:0.74rem; color:#555; margin:0 0 0.35rem;">
                                Menu items whose recipe uses this ingredient:
                            </p>
                            <ul style="font-size:0.74rem; color:#555; margin:0; padding-left:1.1rem;">
                                @foreach($ing['affected_menu_items'] as $affected)
                                    <li>
                                        {{ $affected['menu_item_name'] }}@if($isAllBranches && $affected['branch_name']) <span style="color:#999;">({{ $affected['branch_name'] }})</span>@endif
                                        &mdash; {{ $fmtQty($affected['required_per_unit']) }} {{ $ing['unit'] }} per order
                                    </li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

{{-- ══════════ ANALYTICS INSIGHTS & RECOMMENDATIONS ══════════

     Phase 2d. Decision support, not decisions. Every sentence below is
     generated from a figure in one of the tables above it and can be
     reproduced by hand; nothing here is a model output, and the page does not
     call any of it artificial intelligence, because the application contains
     no AI or ML model.

     The recommendation verbs are deliberately "Consider", "Monitor",
     "Review" — never "buy N units" (the database records no supplier, lead
     time or pack size that could support such a number) and never "remove this
     product" (that is the owner's decision, not a report's).               --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.85rem; font-weight:700; color:#333; margin-bottom:0.25rem;">
        <i class="bi bi-lightbulb" style="color:#F4845F;"></i> Analytics Insights &amp; Recommendations
    </p>
    <p style="font-size:0.7rem; color:#888; margin-bottom:0.7rem;">
        Generated from the figures on this page &mdash; current stock, the selected period's sales, and
        a moving-average projection. Estimates and suggestions for your judgement, not instructions.
    </p>

    @if(empty($intel['insights']))
        <p style="font-size:0.78rem; color:#888;">
            Nothing in the current data meets an alert condition for this branch and period.
        </p>
    @else
        <div class="ai-list">
            @foreach($intel['insights'] as $insight)
                <div class="ai-item ai-{{ $insight['level'] }}">
                    <i class="bi {{ $insight['icon'] }} ai-icon"></i>
                    <div>
                        <p class="ai-title">{{ $insight['title'] }}</p>
                        <p class="ai-message">{{ $insight['message'] }}</p>
                    </div>
                </div>
            @endforeach
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

    .an-toolbar-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }

    /* Matches .btn-primary-custom's metrics so the two buttons sit level;
       the class itself is .btn-edit-custom (existing soft-peach variant),
       giving Export CSV a subtly different colour from Print without a
       new one-off button style. */
    .an-export-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.5rem 1rem;
        font-size: 0.78rem;
        text-decoration: none;
        white-space: nowrap;
    }

    /* 2 columns up to tablet width, 3 from desktop on. SIX tiles now (Phase 2d
       added Gross Profit and Inventory Risk), and 6 divides evenly by both 2
       and 3 — so no tile is ever stranded alone on a half-empty row, which is
       the dead zone the previous 2/4 split existed to avoid. The Projected
       forecast KPI is deliberately still NOT here: the tiles in this grid are
       all things that happened, and dropping an estimate among them is how a
       projection quietly gets read as a fact. It keeps its own dashed tile on
       the chart card below. */
    .an-kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }

    @media (min-width: 768px) {
        .an-kpi-grid { grid-template-columns: repeat(3, 1fr); }
    }

    /* ── Sales Trend & Forecast ──────────────────────────────────────────
       Plain CSS, the page's existing type scale, the Peachy #F4845F accent.
       The forecast's visual language is DASHED everywhere — the chart line,
       the KPI tile's border, the legend swatch — so "estimated" is carried by
       the same signal in all three places rather than by three separate
       conventions the reader has to learn. */
    .fc-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .fc-kpi {
        border: 1px dashed #F4845F;
        border-radius: 8px;
        padding: 0.5rem 0.85rem;
        background: #FFF8F5;
        min-width: 190px;
    }

    .fc-kpi-label {
        font-size: 0.7rem;
        font-weight: 600;
        color: #888;
        text-transform: uppercase;
        margin: 0 0 0.15rem;
    }

    .fc-kpi-value { font-size: 1.3rem; font-weight: 700; color: #333; margin: 0; }

    /* Insufficient data is smaller and grey, not a big confident figure —
       it must not read like an amount. */
    .fc-kpi-none { font-size: 0.95rem; color: #999; }

    .fc-kpi-sub { font-size: 0.68rem; color: #999; margin: 0.15rem 0 0; }

    .fc-periods { font-size: 0.72rem; color: #666; }
    .fc-period { margin: 0 0 0.2rem; display: flex; align-items: center; gap: 0.4rem; }

    .fc-swatch { display: inline-block; width: 18px; height: 0; flex: 0 0 18px; }
    .fc-swatch-actual { border-top: 3px solid #F4845F; }
    .fc-swatch-forecast { border-top: 3px dashed #C96A4A; }

    .fc-note { font-size: 0.68rem; color: #999; margin: 0.6rem 0 0; }
    .fc-note-warn { color: #8a6d3b; }

    @media (max-width: 767px) {
        .fc-head { flex-direction: column; align-items: stretch; }
        .fc-kpi { min-width: 0; }
        .fc-table { min-width: 430px; }
    }

    /* ── Menu Production Capacity ────────────────────────────────────────
       Plain CSS on purpose (no Tailwind anywhere in the admin panel), and
       reusing the page's existing type scale and the Peachy #F4845F accent
       rather than introducing a second visual language. */

    /* The menu name doubles as the detail toggle. Styled as text, not as a
       button, so the table still reads as a table. */
    .mpc-toggle {
        background: none;
        border: 0;
        padding: 0;
        font: inherit;
        color: #333;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        text-align: left;
        /* Comfortable thumb target on a phone without changing desktop rhythm. */
        min-height: 32px;
    }
    .mpc-toggle:hover { color: #F4845F; }
    .mpc-caret { font-size: 0.65rem; color: #bbb; transition: transform 0.15s ease; }
    .mpc-toggle[aria-expanded="true"] .mpc-caret { transform: rotate(90deg); color: #F4845F; }

    .mpc-branch {
        display: block;
        font-size: 0.66rem;
        color: #999;
        margin-left: 1rem;
    }

    .mpc-detail > td { background: #fbfbfb; }
    /* Belt and braces: a `tr` carrying `hidden` relies on the UA stylesheet,
       and this page loads Bootstrap on top of it. Stated explicitly so a
       collapsed detail row can never render open. */
    .mpc-detail[hidden] { display: none; }

    .mpc-badge {
        display: inline-block;
        padding: 0.1rem 0.45rem;
        border-radius: 999px;
        font-size: 0.66rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .mpc-badge-ok   { background: #e8f5ec; color: #2e7d4f; }
    .mpc-badge-low  { background: #fff3e2; color: #b26a12; }
    .mpc-badge-out  { background: #fdeaea; color: #b3261e; }
    .mpc-badge-none { background: #f0f0f0; color: #777; }

    /* "Insufficient Data" / "Unavailable" / "None" / "No Projected Demand".
       Smaller and grey on purpose: an honest refusal to state a figure must
       not sit in the column at the same visual weight as a real number, or it
       reads as one. */
    .mpc-na { font-size: 0.7rem; color: #999; font-weight: 400; }

    /* ── Analytics Insights & Recommendations ────────────────────────────
       Plain CSS, the same badge palette the risk column already uses, so a
       "critical" card and a Critical badge are the same red. */
    .ai-list { display: flex; flex-direction: column; gap: 0.5rem; }

    .ai-item {
        display: flex;
        gap: 0.6rem;
        align-items: flex-start;
        padding: 0.6rem 0.75rem;
        border-radius: 8px;
        border-left: 3px solid #ddd;
        background: #fafafa;
    }
    .ai-icon { font-size: 0.95rem; line-height: 1.3; flex: 0 0 auto; }
    .ai-title { font-size: 0.78rem; font-weight: 700; color: #333; margin: 0 0 0.15rem; }
    .ai-message { font-size: 0.74rem; color: #555; margin: 0; line-height: 1.45; }

    .ai-critical { border-left-color: #b3261e; background: #fdeaea; }
    .ai-critical .ai-icon { color: #b3261e; }
    .ai-warning  { border-left-color: #b26a12; background: #fff3e2; }
    .ai-warning .ai-icon { color: #b26a12; }
    .ai-success  { border-left-color: #2e7d4f; background: #e8f5ec; }
    .ai-success .ai-icon { color: #2e7d4f; }
    .ai-info     { border-left-color: #8aa2b8; background: #f2f6fa; }
    .ai-info .ai-icon { color: #5b7893; }

    /* Below the shared 768px breakpoint the columns are allowed to go narrow
       rather than forcing the page sideways; the wrapper's overflow-x:auto is
       the escape hatch, same as every other admin table. The capacity table
       carries seven columns since Phase 2d, so its floor rises with it. */
    @media (max-width: 767px) {
        .mpc-table { min-width: 680px; }
        .mpc-ing-table { min-width: 380px; }
        .an-toolbar-actions { width: 100%; }
    }
</style>
@endpush

@push('scripts')
<script src="/vendor/chart.umd.min.js"></script>
<script>
    // Sales Trend & Forecast — the page's ONE graph.
    //
    // Two datasets over a single shared label axis: actuals for the historical
    // days, then the projection for the days after them. A point belongs to
    // exactly one of the two, so the same date can never carry both an actual
    // and a forecast.
    //
    // When the forecast is insufficient, the server sends an EMPTY forecast
    // array and the second dataset is not built at all. It is never filled with
    // zeroes to square off the chart: a flat ₱0 line reaching into next week
    // would be a claim that no demand is expected, which is precisely the claim
    // insufficient data cannot support.
    (function () {
        const el = document.getElementById('salesTrendChart');
        if (!el) return;

        {{--
            Phase 3b F10: defense-in-depth only. All three feed from generated
            date strings and numerics in AnalyticsService — no user-controlled
            text reaches them today — but these are the only 3 raw-Blade
            {!! !!} sites in the app, so the JSON_HEX_* flags close the
            "</script>"-style escape a future caller could otherwise inject.
        --}}
        const actualLabels = {!! json_encode($dailySales['labels'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        const actualValues = {!! json_encode($dailySales['values'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        const forecastPoints = {!! json_encode($forecast['sufficient'] ? $forecast['next_7_days'] : [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};

        const labels = actualLabels.concat(forecastPoints.map(p => p.label));

        const datasets = [{
            label: 'Actual Sales (₱)',
            // Padded with nulls across the forecast days so the solid line
            // simply stops where the measured data stops.
            data: actualValues.concat(forecastPoints.map(() => null)),
            borderColor: '#F4845F',
            backgroundColor: 'rgba(244,132,95,0.12)',
            borderWidth: 2,
            pointRadius: 2,
            tension: 0.25,
            fill: true
        }];

        if (forecastPoints.length) {
            // Nulls for every historical day EXCEPT the last, which repeats the
            // final actual value. That single shared point is what joins the
            // dashed line to the solid one instead of leaving it floating in
            // mid-air; it is the last ACTUAL figure, not a forecast for a past
            // date, so nothing is being restated as a prediction.
            const bridge = actualValues.map((v, i) => (i === actualValues.length - 1 ? v : null));

            datasets.push({
                label: 'Forecast (₱, estimated)',
                data: bridge.concat(forecastPoints.map(p => p.value)),
                borderColor: '#C96A4A',
                borderDash: [6, 4],
                borderWidth: 2,
                pointRadius: 2,
                pointStyle: 'rectRot',
                tension: 0,
                fill: false
            });
        }

        new Chart(el.getContext('2d'), {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 10, family: 'Poppins' } }
                    },
                    tooltip: {
                        callbacks: {
                            // Spells out "estimated" on every forecast point, so
                            // a figure read off the tooltip alone still carries
                            // the caveat the legend gives.
                            label: function (ctx) {
                                if (ctx.parsed.y === null) return null;
                                const suffix = ctx.datasetIndex === 1 ? ' (estimated)' : '';
                                return ctx.dataset.label.replace(/ \(.*\)$/, '')
                                    + ': ₱' + ctx.parsed.y.toLocaleString(undefined, {
                                        minimumFractionDigits: 2, maximumFractionDigits: 2
                                    }) + suffix;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10, family: 'Poppins' } } },
                    y: { grid: { color: '#f0f0f0' }, ticks: { font: { size: 10, family: 'Poppins' } } }
                }
            }
        });
    })();

    // Menu Production Capacity detail rows. Everything is already in the DOM —
    // no fetch, no id in a querystring, nothing for a branch-locked viewer to
    // tamper with — so this only flips visibility and the ARIA state.
    // el.hidden rather than style.display, so a row hidden here stays hidden
    // under the print stylesheet's own rules.
    function mpcToggle(button, detailId) {
        const row = document.getElementById(detailId);
        if (!row) return;

        const open = row.hidden;
        row.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function printAnalytics() {
        // Prints in-page via the shared printInFrame() helper (admin.layout) —
        // re-runs the current filters through the dedicated, unbounded print
        // view, but inside a hidden iframe instead of a new tab.
        printInFrame('{{ route('admin.analytics.print') }}' + window.location.search);
    }
</script>
@endpush

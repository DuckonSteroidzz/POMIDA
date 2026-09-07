@extends('admin.layout')

@section('title', 'Summary - Peachy Admin')

@section('content')

@php
    $periodLabels = [
        'today' => 'Today',
        'week'  => 'This Week',
        'month' => 'This Month',
        'custom'=> 'Custom Range',
    ];
    $currentPeriodLabel = $periodLabels[$period] ?? 'Today';
@endphp

<div class="summary-toolbar" style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;flex-wrap:wrap;">
    <p class="page-title" style="margin:0;">Summary</p>
    <div class="no-print" style="display:flex;gap:0.5rem;">
        {{-- Hands the export the SAME period the page is currently reporting:
             the resolved boundaries, not the raw picker input, and always as a
             custom range so a period that is relative to "now" (Today, This
             Week, This Month) cannot re-resolve to a different window between
             the page render and the click. --}}
        <a href="{{ route('admin.export.orders', [
                'period'    => 'custom',
                'date_from' => $periodStart->toDateString(),
                'date_to'   => $periodEnd->toDateString(),
           ]) }}"
           class="btn-primary-custom" style="padding:0.5rem 1rem;font-size:0.78rem;text-decoration:none;">
            <i class="bi bi-download"></i> Export CSV
        </a>
        <button type="button" onclick="window.print()" class="btn-primary-custom" style="padding:0.5rem 1rem;font-size:0.78rem;">
            <i class="bi bi-printer"></i> Print Report
        </button>
    </div>
</div>

{{-- ── Print-only letterhead ──
     Hidden on screen; revealed by the @media print block at the foot of this
     file. The Summary page above is unchanged — everything print-only in this
     view reads the same variables the KPI cards read. --}}
<div class="print-only print-header" style="display:none;">
    <h2 class="print-business">Peachy Cakes &amp; Deli Cafe</h2>
    <p class="print-title">Sales &amp; Profit Report</p>
    <table class="print-meta">
        <tr>
            <td class="print-meta-label">Branch</td>
            <td class="print-meta-value" data-testid="print-branch">{{ $selectedBranchName }}</td>
        </tr>
        <tr>
            <td class="print-meta-label">Period</td>
            <td class="print-meta-value" data-testid="print-period">
                {{ $currentPeriodLabel }} &middot;
                {{ $periodStart->format('M d, Y') }} &ndash; {{ $periodEnd->format('M d, Y') }}
            </td>
        </tr>
        <tr>
            <td class="print-meta-label">Generated</td>
            <td class="print-meta-value" data-testid="print-generated">{{ $printedAt->format('M d, Y g:i A') }}</td>
        </tr>
    </table>
    <hr class="print-rule">
</div>

{{-- ── Period Filter Bar ── --}}
<div class="content-card no-print" style="margin-bottom:1rem;">
    <form method="GET" action="{{ route('admin.summary') }}" id="summary-filter-form"
          style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">

        <div class="summary-period-tabs" style="display:flex;gap:0.4rem;flex-wrap:wrap;">
            @foreach ($periodLabels as $key => $label)
                <button type="submit" name="period" value="{{ $key }}"
                    class="summary-period-btn{{ $period === $key ? ' active' : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label class="form-label-custom">Start Date</label>
                <input type="date" name="date_from" class="form-control-custom"
                       value="{{ $dateFrom }}" style="margin-bottom:0;width:170px;">
            </div>
            <div>
                <label class="form-label-custom">End Date</label>
                <input type="date" name="date_to" class="form-control-custom"
                       value="{{ $dateTo }}" style="margin-bottom:0;width:170px;">
            </div>
            <button type="submit" name="period" value="custom" class="btn-primary-custom" style="padding:0.55rem 1.1rem;">
                <i class="bi bi-search"></i> Apply
            </button>
        </div>
    </form>

    <p style="margin:0.6rem 0 0;font-size:0.75rem;color:#6B7280;">
        Showing <strong style="color:#1F2937;">{{ $currentPeriodLabel }}</strong>
        &middot; {{ $periodStart->format('M d, Y') }} &ndash; {{ $periodEnd->format('M d, Y') }}
        &middot; {{ $selectedBranchName }}
    </p>
</div>

{{-- ── 9 KPI Cards ──

     The revenue chain reads left to right in the order it is calculated:
     Net Revenue, then the Gross Revenue and the Discounts that produce it,
     then COGS, Gross Profit and Margin.

     NET REVENUE LEADS DELIBERATELY. It is the money the till actually took,
     and it is the figure Gross Profit and Margin are both built on. The
     pre-discount total still appears, immediately beside it, because a
     discount the owner cannot see is a profit figure they cannot trust. --}}
<div class="summary-kpi-grid">

    {{-- 1. Net Revenue — the money taken --}}
    <div class="kpi-card">
        <p class="kpi-label">Net Revenue (Money Taken)</p>
        <p class="kpi-value">₱{{ number_format($netRevenue, 2) }}</p>
        @if (is_null($revenueChangePercent))
            <span class="kpi-delta kpi-delta-neutral">No prior-period data</span>
        @else
            <span class="kpi-delta {{ $revenueChangePercent >= 0 ? 'kpi-delta-up' : 'kpi-delta-down' }}">
                <i class="bi {{ $revenueChangePercent >= 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short' }}"></i>
                {{ number_format(abs($revenueChangePercent), 1) }}% vs previous period
            </span>
        @endif
    </div>

    {{-- 2. Gross Revenue — before discounts --}}
    <div class="kpi-card">
        <p class="kpi-label">Gross Revenue (Before Discounts)</p>
        <p class="kpi-value">₱{{ number_format($grossRevenue, 2) }}</p>
    </div>

    {{-- 3. Discounts given — the difference between the two above --}}
    <div class="kpi-card">
        <p class="kpi-label">Discounts Given (PWD / Senior / Voucher)</p>
        <p class="kpi-value">₱{{ number_format($totalDiscounts, 2) }}</p>
    </div>

    {{-- 4. Total COGS --}}
    <div class="kpi-card">
        <p class="kpi-label">Total COGS (Ingredient Cost)</p>
        <p class="kpi-value">₱{{ number_format($totalCogs, 2) }}</p>
    </div>

    {{-- 5. Gross Profit --}}
    <div class="kpi-card">
        <p class="kpi-label">Gross Profit (Net Revenue &minus; COGS)</p>
        <p class="kpi-value">₱{{ number_format($grossProfit, 2) }}</p>
    </div>

    {{-- 6. Gross Margin % --}}
    <div class="kpi-card">
        <p class="kpi-label">Gross Margin (of Net Revenue)</p>
        <p class="kpi-value">
            {{ is_null($grossMarginPercent) ? '—' : number_format($grossMarginPercent, 1) . '%' }}
        </p>
    </div>

    {{-- 7. Total Completed Orders --}}
    <div class="kpi-card">
        <p class="kpi-label">Total Completed Orders</p>
        <p class="kpi-value">{{ number_format($totalOrders) }}</p>
    </div>

    {{-- 8. Out-of-Stock Items --}}
    <div class="kpi-card {{ $outOfStockCount > 0 ? 'kpi-card-warning' : '' }}">
        <p class="kpi-label">Out-of-Stock Menu Items</p>
        <p class="kpi-value">{{ number_format($outOfStockCount) }}</p>
        @if ($outOfStockCount > 0)
            <span class="kpi-delta kpi-delta-down">
                <i class="bi bi-exclamation-triangle-fill"></i> Cannot be made right now
            </span>
        @endif
    </div>

    {{-- 9. Total Inventory Asset Value --}}
    <div class="kpi-card">
        <p class="kpi-label">Total Inventory Asset Value</p>
        <p class="kpi-value">₱{{ number_format($inventoryAssetValue, 2) }}</p>
    </div>

</div>

{{-- ── The cost caveat ──

     One quiet line, and only when there is something to disclose. COGS
     normally comes from the cost snapshotted onto each order line when it was
     completed, which never moves afterwards. A line that never got one is
     priced at today's ingredient cost instead, which means a report of the
     past silently re-prices itself whenever a supplier's price changes. The
     owner is entitled to know which of the two they are reading.

     Reuses .sellers-empty — the page's existing quiet-note type — so this
     stays a note beside the numbers and never a banner competing with them. --}}
@if ($uncostedLineCount > 0)
    <p class="sellers-empty" style="margin-top:0.75rem;" data-testid="cost-caveat">
        <i class="bi bi-info-circle"></i>
        {{ number_format($uncostedLineCount) }} of {{ number_format($soldLineCount) }} sold lines
        have no recorded cost from the time of sale, so they are costed at today&rsquo;s
        ingredient prices. COGS and Gross Profit for those lines are an estimate,
        not a record of what the ingredients cost then.
    </p>
@endif

{{-- ── Top / Least Selling Items (last 30 days, completed orders) ── --}}
<div class="summary-sellers-grid">

    <div class="content-card sellers-card">
        <p class="sellers-title">
            <i class="bi bi-graph-up-arrow"></i> Top Selling Items
        </p>
        @if ($bestSellers->isEmpty())
            <p class="sellers-empty">No completed orders in the last 30 days yet.</p>
        @else
            <table class="sellers-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th style="text-align:right;">Qty Sold</th>
                        <th style="text-align:right;">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bestSellers as $i => $row)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $row->menuItem->name ?? '(deleted item)' }}</td>
                            <td style="text-align:right;font-weight:700;color:#16A34A;">{{ number_format($row->total_qty) }}</td>
                            <td style="text-align:right;">₱{{ number_format($row->total_revenue, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="content-card sellers-card">
        <p class="sellers-title">
            <i class="bi bi-graph-down-arrow"></i> Least Selling Items
        </p>
        @if ($leastSellers->isEmpty())
            <p class="sellers-empty">No completed orders in the last 30 days yet.</p>
        @else
            <table class="sellers-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th style="text-align:right;">Qty Sold</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($leastSellers as $i => $row)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $row->menuItem->name ?? '(deleted item)' }}</td>
                            <td style="text-align:right;font-weight:700;color:#DC2626;">{{ number_format($row->total_qty) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>

{{-- ══════════════════════════════════════════════════════════════════════
     PRINT-ONLY REPORT BODY

     Everything below is hidden on screen and appears only on paper. It is
     built entirely from the variables the KPI cards above already used —
     $grossRevenue, $totalDiscounts, $netRevenue, $totalCogs, $grossProfit and
     $grossMarginPercent — and from
     $itemBreakdown, which the profit service produced in the SAME pass that
     produced those totals. Nothing here re-queries or re-computes anything,
     so a number on the paper cannot disagree with the same number on screen.
     ══════════════════════════════════════════════════════════════════════ --}}
<div class="print-only print-report" style="display:none;">

    {{-- ── Period comparison ── --}}
    <h3 class="print-section-title">Period Comparison</h3>
    <table class="sellers-table print-table" data-testid="print-comparison">
        <thead>
            <tr>
                <th>Measure</th>
                <th class="print-num">This Period</th>
                <th class="print-num">Previous Period</th>
                <th class="print-num">Change</th>
            </tr>
        </thead>
        <tbody>
            <tr data-testid="print-compare-revenue">
                <td>Gross Revenue (before discounts)</td>
                <td class="print-num">₱{{ number_format($grossRevenue, 2) }}</td>
                <td class="print-num">₱{{ number_format($prevGrossRevenue, 2) }}</td>
                <td class="print-num">—</td>
            </tr>
            <tr data-testid="print-compare-discounts">
                <td>Less: Discounts Given (PWD / Senior / Voucher)</td>
                <td class="print-num">&minus;₱{{ number_format($totalDiscounts, 2) }}</td>
                <td class="print-num">&minus;₱{{ number_format($prevDiscounts, 2) }}</td>
                <td class="print-num">—</td>
            </tr>
            <tr data-testid="print-compare-net-revenue">
                <td>Net Revenue (money taken)</td>
                <td class="print-num">₱{{ number_format($netRevenue, 2) }}</td>
                <td class="print-num">₱{{ number_format($prevNetRevenue, 2) }}</td>
                <td class="print-num">
                    {{ is_null($revenueChangePercent)
                        ? '—'
                        : ($revenueChangePercent >= 0 ? '+' : '−') . number_format(abs($revenueChangePercent), 1) . '%' }}
                </td>
            </tr>
            <tr data-testid="print-compare-cogs">
                <td>Total COGS (Ingredient Cost)</td>
                <td class="print-num">₱{{ number_format($totalCogs, 2) }}</td>
                <td class="print-num">₱{{ number_format($prevCogs, 2) }}</td>
                <td class="print-num">—</td>
            </tr>
            <tr data-testid="print-compare-profit">
                <td>Gross Profit (Net Revenue &minus; COGS)</td>
                <td class="print-num">₱{{ number_format($grossProfit, 2) }}</td>
                <td class="print-num">₱{{ number_format($prevGrossProfit, 2) }}</td>
                <td class="print-num">—</td>
            </tr>
            <tr data-testid="print-compare-margin">
                <td>Gross Margin (of Net Revenue)</td>
                <td class="print-num">{{ is_null($grossMarginPercent) ? '—' : number_format($grossMarginPercent, 1) . '%' }}</td>
                <td class="print-num">{{ is_null($prevMarginPercent) ? '—' : number_format($prevMarginPercent, 1) . '%' }}</td>
                <td class="print-num">—</td>
            </tr>
            <tr data-testid="print-compare-orders">
                <td>Completed Orders</td>
                <td class="print-num">{{ number_format($totalOrders) }}</td>
                <td class="print-num">{{ number_format($prevOrders) }}</td>
                <td class="print-num">—</td>
            </tr>
        </tbody>
    </table>
    <p class="print-note">
        Previous period: {{ $prevPeriodStart->format('M d, Y') }} &ndash; {{ $prevPeriodEnd->format('M d, Y') }}
    </p>

    {{-- ── Full item breakdown: EVERY item sold in the period ── --}}
    <h3 class="print-section-title">Item Breakdown &mdash; All Items Sold This Period</h3>

    @if (empty($itemBreakdown))
        <p class="print-empty" data-testid="print-items-empty">
            No items were sold in this period. There is nothing to break down.
        </p>
    @else
        <table class="sellers-table print-table" data-testid="print-item-breakdown">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th class="print-num">Qty Sold</th>
                    <th class="print-num">Revenue</th>
                    <th class="print-num">Cost</th>
                    <th class="print-num">Profit</th>
                    <th class="print-num">Margin %</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($itemBreakdown as $i => $row)
                    <tr data-testid="print-item-row">
                        <td>{{ $i + 1 }}</td>
                        <td data-testid="print-item-name">{{ $row['name'] }}</td>
                        <td class="print-num" data-testid="print-item-qty">{{ number_format($row['quantity']) }}</td>
                        <td class="print-num" data-testid="print-item-revenue">₱{{ number_format($row['revenue'], 2) }}</td>
                        <td class="print-num" data-testid="print-item-cost">₱{{ number_format($row['cost'], 2) }}</td>
                        <td class="print-num" data-testid="print-item-profit">₱{{ number_format($row['profit'], 2) }}</td>
                        <td class="print-num" data-testid="print-item-margin">{{ is_null($row['margin_percent']) ? '—' : number_format($row['margin_percent'], 1) . '%' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                {{-- The totals are the KPI figures themselves, not a re-sum of
                     the rows above — that is what makes the bottom of this
                     table and the top of this report the same numbers. --}}
                <tr class="print-totals" data-testid="print-item-totals">
                    <td colspan="2">TOTAL (before discounts)</td>
                    <td class="print-num">{{ number_format(collect($itemBreakdown)->sum('quantity')) }}</td>
                    <td class="print-num" data-testid="print-total-revenue">₱{{ number_format($grossRevenue, 2) }}</td>
                    <td class="print-num" data-testid="print-total-cost">₱{{ number_format($totalCogs, 2) }}</td>
                    <td class="print-num" data-testid="print-total-line-profit">₱{{ number_format($grossRevenue - $totalCogs, 2) }}</td>
                    <td class="print-num" data-testid="print-total-line-margin">{{ $grossRevenue > 0 ? number_format(($grossRevenue - $totalCogs) / $grossRevenue * 100, 1) . '%' : '—' }}</td>
                </tr>
                {{-- A discount is given on an ORDER, not on a menu item, so it
                     cannot be attributed to any row above. It is subtracted
                     here instead, in full view, and the NET line beneath is
                     the report's real Gross Profit — the same figure the KPI
                     card states. --}}
                <tr class="print-totals" data-testid="print-item-discounts">
                    <td colspan="2">Less: Discounts Given</td>
                    <td class="print-num">—</td>
                    <td class="print-num" data-testid="print-total-discounts">&minus;₱{{ number_format($totalDiscounts, 2) }}</td>
                    <td class="print-num">—</td>
                    <td class="print-num" data-testid="print-total-discount-effect">&minus;₱{{ number_format($totalDiscounts, 2) }}</td>
                    <td class="print-num">—</td>
                </tr>
                <tr class="print-totals" data-testid="print-item-net-totals">
                    <td colspan="2">NET TOTAL</td>
                    <td class="print-num">—</td>
                    <td class="print-num" data-testid="print-total-net-revenue">₱{{ number_format($netRevenue, 2) }}</td>
                    <td class="print-num" data-testid="print-total-net-cost">₱{{ number_format($totalCogs, 2) }}</td>
                    <td class="print-num" data-testid="print-total-profit">₱{{ number_format($grossProfit, 2) }}</td>
                    <td class="print-num" data-testid="print-total-margin">{{ is_null($grossMarginPercent) ? '—' : number_format($grossMarginPercent, 1) . '%' }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- The printed copy of the on-screen caveat: a report that leaves the
         building must disclose how its costs were priced. Silent at zero. --}}
    @if ($uncostedLineCount > 0)
        <p class="print-note" data-testid="print-cost-caveat">
            Cost note: {{ number_format($uncostedLineCount) }} of {{ number_format($soldLineCount) }}
            sold lines have no recorded cost from the time of sale, so they are costed at
            today&rsquo;s ingredient prices. COGS and Gross Profit for those lines are an
            estimate, not a record of what the ingredients cost then.
        </p>
    @endif

    {{-- ── Out-of-stock items, by name ── --}}
    <h3 class="print-section-title">Out-of-Stock Menu Items ({{ number_format($outOfStockCount) }})</h3>
    @if ($outOfStockItems->isEmpty())
        <p class="print-empty" data-testid="print-oos-empty">
            No menu items are out of stock. Every item on the menu can be made right now.
        </p>
    @else
        <ul class="print-oos-list" data-testid="print-oos-list">
            @foreach ($outOfStockItems as $oos)
                <li data-testid="print-oos-item">{{ $oos->name }}</li>
            @endforeach
        </ul>
    @endif

    {{-- ── Footer ── --}}
    <div class="print-footer" data-testid="print-footer">
        Printed by {{ $printedBy }} on {{ $printedAt->format('M d, Y g:i A') }}
    </div>

</div>

@endsection

@push('styles')
<style>
    .summary-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
    }

    .kpi-card {
        background: #fff;
        border-radius: 10px;
        padding: 1.1rem 1.25rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        border-top: 4px solid #F4845F;
    }

    .kpi-card-warning { border-top-color: #DC2626; }

    .kpi-label {
        margin: 0 0 0.5rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: #6B7280;
    }

    .kpi-value {
        margin: 0;
        font-size: 1.7rem;
        font-weight: 800;
        color: #1F2937;
        line-height: 1.15;
    }

    .kpi-delta {
        display: inline-block;
        margin-top: 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .kpi-delta-up { color: #16A34A; }
    .kpi-delta-down { color: #DC2626; }
    .kpi-delta-neutral { color: #9CA3AF; }

    .summary-period-tabs {
        border: 1px solid #E5E7EB;
        border-radius: 8px;
        padding: 0.25rem;
        background: #F9FAFB;
    }

    .summary-period-btn {
        border: none;
        background: transparent;
        padding: 0.45rem 0.9rem;
        font-size: 0.78rem;
        font-weight: 600;
        color: #4B5563;
        border-radius: 6px;
        cursor: pointer;
    }

    .summary-period-btn:hover { background: #F0F0F0; }

    .summary-period-btn.active {
        background: #F4845F;
        color: #fff;
    }

    .summary-sellers-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-top: 1rem;
    }

    .sellers-card { padding: 1.1rem 1.25rem; }

    .sellers-title {
        margin: 0 0 0.75rem;
        font-size: 0.85rem;
        font-weight: 700;
        color: #1F2937;
    }

    .sellers-empty {
        margin: 0;
        font-size: 0.8rem;
        color: #6B7280;
    }

    .sellers-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .sellers-table th {
        text-align: left;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #6B7280;
        padding: 0.4rem 0.4rem;
        border-bottom: 1px solid #E5E7EB;
    }

    .sellers-table td {
        padding: 0.5rem 0.4rem;
        color: #1F2937;
        border-bottom: 1px solid #F3F4F6;
    }

    .sellers-table tbody tr:last-child td { border-bottom: none; }

    @media (max-width: 768px) {
        .summary-sellers-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 600px) {
        .summary-kpi-grid { grid-template-columns: 1fr; }
    }

    /* Print-only content is hidden on screen. This rule MUST stay above the
       @media print block below: the hide rule and the reveal rule have
       identical specificity and both carry !important, so whichever comes
       last in the source wins. It used to sit at the very bottom of this
       file, which meant it also beat the reveal rule and the print-only
       header never actually reached the paper. */
    .print-only { display: none !important; }

    /* ── Print-report typography ──
       Screen-side definitions for the print-only blocks. They live outside
       @media print so the classes are always defined (a class that exists
       only inside a media query is invisible to anything else), while the
       hide rule just above keeps the whole report off the screen. Colours
       and sizes are the same scale the Summary cards and .sellers-table
       already use. */
    .print-business {
        margin: 0 0 0.15rem;
        font-size: 1.3rem;
        font-weight: 800;
        color: #1F2937;
    }

    .print-title {
        margin: 0 0 0.5rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: #F4845F;
        letter-spacing: 0.02em;
    }

    .print-meta {
        border-collapse: collapse;
        font-size: 0.8rem;
        color: #1F2937;
    }

    .print-meta-label {
        padding: 0.1rem 0.75rem 0.1rem 0;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #6B7280;
        vertical-align: top;
        white-space: nowrap;
    }

    .print-meta-value { padding: 0.1rem 0; }

    .print-rule {
        border: none;
        border-top: 2px solid #F4845F;
        margin: 0.6rem 0 0;
    }

    .print-section-title {
        margin: 1rem 0 0.5rem;
        font-size: 0.85rem;
        font-weight: 700;
        color: #1F2937;
    }

    /* Extends .sellers-table (defined above) rather than replacing it, so the
       printed tables use the app's existing table treatment. */
    .print-table {
        width: 100%;
        table-layout: fixed;
    }

    .print-table td,
    .print-table th { word-wrap: break-word; }

    .print-num { text-align: right; }

    .print-totals td {
        border-top: 2px solid #1F2937;
        border-bottom: none;
        font-weight: 700;
        color: #1F2937;
    }

    .print-note,
    .print-empty {
        margin: 0.35rem 0 0;
        font-size: 0.75rem;
        color: #6B7280;
    }

    .print-oos-list {
        margin: 0;
        padding-left: 1.1rem;
        font-size: 0.8rem;
        color: #1F2937;
    }

    .print-oos-list li { padding: 0.1rem 0; }

    .print-footer {
        margin-top: 1.25rem;
        padding-top: 0.4rem;
        border-top: 1px solid #E5E7EB;
        font-size: 0.72rem;
        color: #6B7280;
    }

    @media print {
        /* A4 by default, but the layout is fluid so Letter prints the same
           without horizontal overflow. The bottom margin box carries the page
           number; the "printed by" line lives in .print-footer in the document
           itself so it survives engines that ignore margin boxes. */
        @page {
            size: A4;
            margin: 14mm 12mm 16mm;

            @bottom-right {
                content: "Page " counter(page) " of " counter(pages);
                font-size: 9pt;
                color: #6B7280;
            }
        }

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

        .pc-branchbar form { display: none !important; }

        .alert-success-custom,
        .alert-error-custom { display: none !important; }

        .print-only { display: block !important; }
        .print-header h2 { font-size: 1.3rem; font-weight: 700; }

        .kpi-card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            border-top: 3px solid #F4845F !important;
            page-break-inside: avoid;
        }

        /* Top/Least Selling 5 are the SCREEN's summary of the sellers. On
           paper the full item breakdown supersedes them, so printing both
           would repeat the same five rows and push the document onto an
           extra page for nothing. */
        .summary-sellers-grid { display: none !important; }

        .sellers-card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            page-break-inside: avoid;
        }

        /* ── The printed report ── */
        .print-report {
            page-break-before: auto;
            font-size: 10pt;
        }

        .print-section-title {
            page-break-after: avoid;
            break-after: avoid;
        }

        /* No row is ever split across a page boundary, and the column header
           repeats at the top of every page the table spans. */
        .print-table { page-break-inside: auto; }

        .print-table thead { display: table-header-group; }
        .print-table tfoot { display: table-row-group; }

        .print-table tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* Hairlines only — no filled header bands, which would drain ink for
           no gain on a black-and-white office printer. */
        .print-table th {
            background: transparent !important;
            border-bottom: 1px solid #999 !important;
        }

        .print-footer {
            page-break-inside: avoid;
            break-inside: avoid;
        }
    }
</style>
@endpush

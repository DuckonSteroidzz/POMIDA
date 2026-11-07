@extends('admin.layout')

@section('title', 'Refunds - Peachy Admin')

{{--
    Refunds (QA, 5 Nov 2026) — every refund, owed AND refunded.

    A refund is a cancelled GCash order the customer had already marked paid
    (payment_status refund_pending → refunded). Before this page the only
    place one showed was the board's Refunds Owed band, and Mark Refunded
    made it vanish for good. Here a settled refund stays, reads "Refunded"
    with who and when, and View opens it again any time.

    See App\Http\Controllers\Admin\RefundController.
--}}

@section('content')

<div class="rf-header">
    <div style="min-width:0;">
        <p class="rf-title">Refunds</p>
        <p class="rf-sub">GCash orders cancelled after the customer paid. Send the money back, then mark it refunded.</p>
    </div>
    @if($counts['owed'] > 0)
        <p class="rf-owed-total" data-testid="refunds-owed-total">
            <i class="bi bi-cash-stack"></i>
            {{ $counts['owed'] }} owed · ₱{{ number_format($owedTotal, 2) }} to send back
        </p>
    @endif
</div>

@if($errors->has('payment'))
    <div class="rf-alert" role="alert">
        <i class="bi bi-exclamation-circle"></i> {{ $errors->first('payment') }}
    </div>
@endif

{{-- Status tabs: plain links, so a filtered view survives a reload. --}}
@php
    $rfTab = fn ($status) => route('admin.refunds', array_filter([
        'status' => $status === 'all' ? null : $status,
        'from'   => $filters['from'],
        'to'     => $filters['to'],
        'q'      => $filters['q'] !== '' ? $filters['q'] : null,
    ]));
@endphp
<nav class="rf-tabs" aria-label="Refund status">
    <a href="{{ $rfTab('all') }}" class="rf-tab {{ $filters['status'] === 'all' ? 'is-active' : '' }}" data-testid="refunds-tab-all">
        All <span class="rf-tab-count">{{ $counts['all'] }}</span>
    </a>
    <a href="{{ $rfTab('owed') }}" class="rf-tab {{ $filters['status'] === 'owed' ? 'is-active' : '' }}" data-testid="refunds-tab-owed">
        Refund owed <span class="rf-tab-count">{{ $counts['owed'] }}</span>
    </a>
    <a href="{{ $rfTab('refunded') }}" class="rf-tab {{ $filters['status'] === 'refunded' ? 'is-active' : '' }}" data-testid="refunds-tab-refunded">
        Refunded <span class="rf-tab-count">{{ $counts['refunded'] }}</span>
    </a>
</nav>

<div class="rf-card rf-filter-card">
    <form method="GET" action="{{ route('admin.refunds') }}" class="rf-filter">
        @if($filters['status'] !== 'all')
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
        @endif
        <div class="rf-field rf-field-search">
            <label class="rf-label" for="rf-q">Search</label>
            <input id="rf-q" type="search" name="q" class="rf-input" value="{{ $filters['q'] }}"
                   maxlength="100" placeholder="Order #, customer name, email or phone">
        </div>
        <div class="rf-field">
            <label class="rf-label" for="rf-from">Cancelled from</label>
            <input id="rf-from" type="date" name="from" class="rf-input" value="{{ $filters['from'] }}">
        </div>
        <div class="rf-field">
            <label class="rf-label" for="rf-to">Cancelled to</label>
            <input id="rf-to" type="date" name="to" class="rf-input" value="{{ $filters['to'] }}">
        </div>
        <div class="rf-field rf-field-actions">
            <button type="submit" class="rf-btn rf-btn-solid"><i class="bi bi-funnel"></i> Apply</button>
            <a href="{{ route('admin.refunds') }}" class="rf-btn rf-btn-ghost">Reset</a>
        </div>
    </form>
</div>

<div class="rf-card rf-table-card">
    <table class="rf-table">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Branch</th>
                <th class="rf-right">Amount</th>
                <th>GCash / phone</th>
                <th>Cancelled on</th>
                <th>Cancelled by</th>
                <th>Status</th>
                <th>Refunded on</th>
                <th>Refunded by</th>
                <th class="rf-right"><span class="rf-sr">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse($refunds as $refund)
                @php $owed = $refund->refundIsOwed(); @endphp
                <tr class="rf-row" data-refund-row="{{ $refund->id }}" data-refund-status="{{ $owed ? 'owed' : 'refunded' }}">
                    <td data-label="Order #" class="rf-order-no"><a href="{{ route('admin.refunds.show', $refund->id) }}">{{ $refund->order_number }}</a></td>
                    <td data-label="Customer" class="rf-name"><span title="{{ $refund->customer?->email }}">{{ $refund->customer->name ?? ($refund->processed_by ? 'Walk-in customer' : 'Guest') }}</span></td>
                    <td data-label="Branch">{{ $refund->branch->name ?? '—' }}</td>
                    <td data-label="Amount" class="rf-right rf-num rf-amount">₱{{ number_format((float) $refund->total, 2) }}</td>
                    <td data-label="GCash / phone">
                        @if($refund->customer?->contact_number)
                            {{ $refund->customer->contact_number }}
                        @else
                            <span class="rf-muted">Not recorded</span>
                        @endif
                    </td>
                    <td data-label="Cancelled on" class="rf-date">@if($refund->cancelled_at)<span>{{ \App\Support\ShopDay::format($refund->cancelled_at, 'M d, Y') }} <span class="rf-time">{{ \App\Support\ShopDay::format($refund->cancelled_at, 'h:i A') }}</span></span>@else — @endif</td>
                    <td data-label="Cancelled by">{{ $refund->cancelledByLabel() }}</td>
                    <td data-label="Status">
                        <span class="rf-badge {{ $owed ? 'rf-badge-owed' : 'rf-badge-done' }}">{{ $owed ? 'Refund owed' : 'Refunded' }}</span>
                    </td>
                    <td data-label="Refunded on" class="rf-date">
                        @if($owed)
                            <span class="rf-muted">—</span>
                        @elseif($refund->refunded_at)
                            <span>{{ \App\Support\ShopDay::format($refund->refunded_at, 'M d, Y') }} <span class="rf-time">{{ \App\Support\ShopDay::format($refund->refunded_at, 'h:i A') }}</span></span>
                        @else
                            <span class="rf-muted">Not recorded</span>
                        @endif
                    </td>
                    <td data-label="Refunded by" class="rf-name">
                        @if($owed)
                            <span class="rf-muted">—</span>
                        @else
                            {{ $refund->refundedBy->name ?? 'Not recorded' }}
                        @endif
                    </td>
                    <td data-label="" class="rf-actions">
                        <a href="{{ route('admin.refunds.show', $refund->id) }}" class="rf-btn rf-btn-ghost rf-btn-sm" data-testid="refund-view">
                            <i class="bi bi-eye"></i> View
                        </a>
                        @if($owed)
                            <form action="{{ route('admin.orders.payment.refunded', $refund->id) }}" method="POST" style="margin:0;"
                                  onsubmit="return confirm('Confirm you have already sent the ₱{{ number_format((float) $refund->total, 2) }} GCash refund for order #{{ $refund->order_number }}?')">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="from" value="refunds">
                                <button type="submit" class="rf-btn rf-btn-done rf-btn-sm" data-testid="refund-mark">
                                    <i class="bi bi-check-circle"></i> Mark Refunded
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr class="rf-empty-row">
                    <td colspan="11">
                        <div class="rf-empty">
                            <i class="bi bi-cash-stack"></i>
                            <p>{{ $filters['status'] === 'owed' ? 'No refunds owed' : 'No refunds found' }}</p>
                            <span>A refund appears here when a GCash order is cancelled after the customer paid — online or at the counter.</span>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($refunds->hasPages())
        <div class="rf-pager">
            <span>Showing {{ $refunds->firstItem() }}–{{ $refunds->lastItem() }} of {{ $refunds->total() }}</span>
            <span class="rf-pager-nav">
                @if($refunds->onFirstPage())
                    <span class="rf-btn rf-btn-ghost rf-btn-sm is-disabled"><i class="bi bi-chevron-left"></i> Previous</span>
                @else
                    <a href="{{ $refunds->previousPageUrl() }}" class="rf-btn rf-btn-ghost rf-btn-sm"><i class="bi bi-chevron-left"></i> Previous</a>
                @endif
                @if($refunds->hasMorePages())
                    <a href="{{ $refunds->nextPageUrl() }}" class="rf-btn rf-btn-ghost rf-btn-sm">Next <i class="bi bi-chevron-right"></i></a>
                @else
                    <span class="rf-btn rf-btn-ghost rf-btn-sm is-disabled">Next <i class="bi bi-chevron-right"></i></span>
                @endif
            </span>
        </div>
    @endif
</div>

@endsection

@push('styles')
@include('admin.partials.refund-styles')
@endpush

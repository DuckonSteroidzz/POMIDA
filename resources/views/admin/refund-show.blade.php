@extends('admin.layout')

@section('title', 'Refund ' . $order->order_number . ' - Peachy Admin')

{{--
    One refund, read-only (QA, 5 Nov 2026: "we can't open it again").

    Reachable before AND after Mark Refunded, from the Refunds page, the
    board's Refunds Owed band and the bell. The only action here is the
    existing Mark Refunded, and only while the refund is still owed.
    See RefundController::show().
--}}

@section('content')
@php
    $owed = $order->refundIsOwed();
    $amount = '₱' . number_format((float) $order->total, 2);
    $phone = $order->customer?->contact_number;
    $refundedOn = $order->refunded_at ? \App\Support\ShopDay::format($order->refunded_at, 'M d, Y h:i A') : null;
    $refundedBy = $order->refundedBy?->name;
    // A counter (walk-in) order: keyed in by staff, no account, no "I Have Paid".
    $walkIn = $order->user_id === null && $order->processed_by !== null;
@endphp

<div class="rf-detail">
    <a href="{{ route('admin.refunds') }}" class="rf-btn rf-btn-ghost rf-btn-sm" style="margin-bottom:0.9rem;">
        <i class="bi bi-arrow-left"></i> All refunds
    </a>

    <div class="rf-header">
        <div style="min-width:0;">
            <p class="rf-title">Order #{{ $order->order_number }}</p>
            <p class="rf-sub">{{ $order->branch->name ?? 'Unknown branch' }} · {{ $order->type === 'dine_in' ? 'Dine-in' : ($order->type === 'pick_up' ? 'Pick-up' : 'Walk-in') }} · GCash</p>
        </div>
        <span class="rf-badge {{ $owed ? 'rf-badge-owed' : 'rf-badge-done' }}" data-testid="refund-status" style="font-size:0.8rem;padding:0.35rem 0.8rem;">
            {{ $owed ? 'Refund owed' : 'Refunded' }}
        </span>
    </div>

    @if($errors->has('payment'))
        <div class="rf-alert" role="alert">
            <i class="bi bi-exclamation-circle"></i> {{ $errors->first('payment') }}
        </div>
    @endif

    <div class="rf-detail-grid">
        <div>
            <div class="rf-card rf-section">
                @if($owed)
                    <div class="rf-callout rf-callout-owed">
                        <strong>Send {{ $amount }} back to the customer's GCash</strong>
                        @if($phone) ({{ $phone }})@endif, then press <strong>Mark Refunded</strong>.
                        @unless($phone)
                            No phone number is on file — use the payment screenshot or ask the customer.
                        @endunless
                    </div>
                    <form action="{{ route('admin.orders.payment.refunded', $order->id) }}" method="POST" class="rf-detail-actions"
                          onsubmit="return confirm('Confirm you have already sent the {{ $amount }} GCash refund for order #{{ $order->order_number }}?')">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="from" value="refunds">
                        <button type="submit" class="rf-btn rf-btn-done" data-testid="refund-mark">
                            <i class="bi bi-check-circle"></i> Mark Refunded
                        </button>
                    </form>
                @else
                    <div class="rf-callout rf-callout-done">
                        <strong>{{ $amount }} refunded</strong>
                        {{ $refundedOn ? 'on ' . $refundedOn : '' }}{{ $refundedBy ? ' by ' . $refundedBy : '' }}.
                        @if(! $refundedOn)
                            This refund was settled before the date and staff name were recorded.
                        @endif
                    </div>
                @endif
            </div>

            <div class="rf-card rf-section">
                <p class="rf-section-title">Refund details</p>
                <dl class="rf-dl">
                    <dt>Amount</dt>
                    <dd><strong>{{ $amount }}</strong></dd>

                    <dt>Customer</dt>
                    <dd>{{ $order->customer->name ?? ($walkIn ? 'Walk-in customer' : 'Guest') }}@if($order->customer?->email)<span class="rf-muted rf-block">{{ $order->customer->email }}</span>@endif</dd>

                    <dt>GCash / phone</dt>
                    <dd>{{ $phone ?: 'Not recorded' }}</dd>

                    <dt>Payment screenshot</dt>
                    <dd>@include('admin.partials.gcash-proof-button')</dd>

                    <dt>Cancelled on</dt>
                    <dd>{{ $order->cancelled_at ? \App\Support\ShopDay::format($order->cancelled_at, 'M d, Y h:i A') : '—' }}</dd>

                    <dt>Cancelled by</dt>
                    <dd>{{ $order->cancelledByLabel() }}</dd>

                    @if($order->cancellation_reason)
                        <dt>Reason</dt>
                        <dd>{{ $order->cancellation_reason }}</dd>
                    @endif

                    <dt>Refunded on</dt>
                    <dd>{{ $owed ? '—' : ($refundedOn ?? 'Not recorded') }}</dd>

                    <dt>Refunded by</dt>
                    <dd>{{ $owed ? '—' : ($refundedBy ?? 'Not recorded') }}</dd>
                </dl>
            </div>
        </div>

        <div>
            <div class="rf-card rf-section">
                <p class="rf-section-title">What happened</p>
                <ol class="rf-timeline">
                    <li class="is-done">
                        Order placed, paying by GCash
                        <span class="rf-when">{{ \App\Support\ShopDay::format($order->created_at, 'M d, Y h:i A') }}</span>
                    </li>
                    <li class="is-done">
                        {{ $walkIn ? 'Paid by GCash at the counter' : 'Customer tapped “I Have Paid”' }}
                        @if($order->gcash_proof_uploaded_at)
                            <span class="rf-when">Screenshot sent {{ \App\Support\ShopDay::format($order->gcash_proof_uploaded_at, 'M d, Y h:i A') }}</span>
                        @endif
                    </li>
                    <li class="is-done">
                        {{ match ($order->cancelledByLabel()) { 'Customer' => 'Cancelled by the customer', 'Staff' => 'Cancelled by staff', default => 'Cancelled' } }} — refund owed
                        <span class="rf-when">{{ $order->cancelled_at ? \App\Support\ShopDay::format($order->cancelled_at, 'M d, Y h:i A') : '' }}</span>
                    </li>
                    <li class="{{ $owed ? 'is-waiting' : 'is-done' }}">
                        @if($owed)
                            Waiting for staff to send the refund
                        @else
                            Refunded{{ $refundedBy ? ' by ' . $refundedBy : '' }}
                            <span class="rf-when">{{ $refundedOn ?? 'Date not recorded' }}</span>
                        @endif
                    </li>
                </ol>
            </div>

            <div class="rf-card rf-section">
                <p class="rf-section-title">Items</p>
                <ul class="rf-items">
                    @foreach($order->items as $item)
                        <li>
                            <span>{{ $item->quantity }}× {{ $item->displayName() }}{{ $item->removedIngredients->isNotEmpty() ? ' (' . implode(', ', $item->removalLabels()) . ')' : '' }}</span>
                            <span class="rf-num">₱{{ number_format((float) $item->subtotal, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
                @if((float) $order->discount_amount > 0)
                    <div class="rf-items" style="display:flex;justify-content:space-between;font-size:0.84rem;padding-top:0.5rem;color:#2E7D5B;">
                        <span>{{ $order->discountDisplayLabel() ?: 'Discount' }}</span>
                        <span class="rf-num">-₱{{ number_format((float) $order->discount_amount, 2) }}</span>
                    </div>
                @endif
                <div class="rf-total-line">
                    <span>Total paid by GCash</span>
                    <span class="rf-num">{{ $amount }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

@include('admin.partials.gcash-proof-modal')

@endsection

@push('styles')
@include('admin.partials.refund-styles')
@endpush

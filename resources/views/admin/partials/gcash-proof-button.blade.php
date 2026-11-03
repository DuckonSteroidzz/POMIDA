{{--
    The GCash screenshot control for one order: View Receipt when the customer
    attached one, a small note when not, nothing for any other payment method.
    Needs admin.partials.gcash-proof-modal once on the page.
--}}
@if(strtolower((string) $order->payment_method) === 'gcash')
    @if($order->hasGcashProof())
        <button
            type="button"
            class="gp-view-btn"
            data-testid="gcash-proof-view"
            data-proof-url="{{ $order->gcashProofUrl() }}"
            data-order-number="{{ $order->order_number }}"
            onclick="openGcashProof(this)"
        >
            <i class="bi bi-receipt"></i>
            View Receipt
        </button>
    @else
        <p class="gp-none" data-testid="gcash-proof-none">No screenshot attached</p>
    @endif
@endif

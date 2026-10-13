{{--
    The back button of an inner customer page, at the left of the header.

    One look and one place on every inner page (category, item, cart, receipt,
    GCash payment, Vouchers & Rewards, Account, Help), so a customer always finds the
    way back in the same spot. It goes to the page's PARENT, never
    history.back(): a customer who arrived from a QR code, a notification or a
    redirect has no useful history, and Back must still lead somewhere sensible.

    Usage: @include('customer.partials.header-back', ['href' => …, 'label' => 'Menu'])
    The label is the destination; it is spoken to screen readers and shown
    from the sm breakpoint up (the arrow alone on phones keeps the logo room).
--}}
<a href="{{ $href }}"
   data-header-back
   aria-label="Back to {{ $label }}"
   class="inline-flex h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-full border border-peach-soft bg-white px-3 text-base font-bold text-peach-red no-underline shadow-sm transition hover:bg-peach-soft">
    <i class="bi bi-arrow-left text-lg leading-none" aria-hidden="true"></i>
    <span class="hidden sm:inline">{{ $label }}</span>
</a>

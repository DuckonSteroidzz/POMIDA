{{--
    Pick-up store contact — shown to pick-up customers (signed in OR guest) on
    their order page and receipt. A pick-up customer who paid by GCash is not in
    the shop, so "Request Assistance" (which calls a server to a table) does not
    apply to them. This surfaces the shop's real, stored phone and email so they
    can raise a wrong order or a refund question directly.

    Expects: $storeContact (array from App\Support\StoreContact::forBranch()).
    Reuses card-surface and the peach tokens already defined on the host page.
--}}
@php($storeContact = $storeContact ?? [])
@if(!empty($storeContact['contact_number']) || !empty($storeContact['email']))
<section class="card-surface mt-5 overflow-hidden">
    <div class="border-b border-peach-soft bg-peach-soft/50 px-5 py-4 sm:px-6">
        <div class="flex items-center gap-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-peach-red">
                <i class="bi bi-headset"></i>
            </span>
            <div class="min-w-0">
                <h2 class="font-display text-lg font-bold text-peach-deep">Need help with this pick-up order?</h2>
                <p class="text-xs text-peach-deep/55">Wrong order, a missing item or a payment or refund question — contact {{ $storeContact['business_name'] ?? 'the shop' }} directly.</p>
            </div>
        </div>
    </div>

    <div class="grid gap-3 p-5 sm:p-6">
        @if(!empty($storeContact['contact_number']))
        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $storeContact['contact_number']) }}" class="flex items-center gap-3 rounded-xl border border-peach-soft bg-white p-3 no-underline transition hover:bg-peach-soft/40">
            <i class="bi bi-telephone text-peach-red"></i>
            <span class="min-w-0 flex-1">
                <span class="block text-[0.68rem] font-black uppercase tracking-[0.12em] text-peach-deep/45">Phone</span>
                <span class="block text-sm font-bold text-peach-deep">{{ $storeContact['contact_number'] }}</span>
            </span>
            <i class="bi bi-chevron-right text-peach-red/40"></i>
        </a>
        @endif

        @if(!empty($storeContact['email']))
        <a href="mailto:{{ $storeContact['email'] }}" class="flex items-center gap-3 rounded-xl border border-peach-soft bg-white p-3 no-underline transition hover:bg-peach-soft/40">
            <i class="bi bi-envelope text-peach-red"></i>
            <span class="min-w-0 flex-1">
                <span class="block text-[0.68rem] font-black uppercase tracking-[0.12em] text-peach-deep/45">Email</span>
                <span class="block break-all text-sm font-bold text-peach-deep">{{ $storeContact['email'] }}</span>
            </span>
            <i class="bi bi-chevron-right text-peach-red/40"></i>
        </a>
        @endif
    </div>
</section>
@endif

{{--
    Where the stamp card goes at a branch that has none to show: it is
    switched off there (FreeDesserts::active() is false). A branch that is ON
    but has no reward it can give does NOT come here — it shows the real card
    with "Reward coming soon" (free-dessert-stamp-card, $rewardPending). Same
    place and same heading as the card, so every branch has the same layout.

    Stamps are read from completed orders and nothing here changes them, so what
    the customer has collected at this branch is saved and counts once the card
    runs there, and a full card is paid at the next completion there (grantDue).
    Included by free-dessert-stamp-card with its $fillWidth.
--}}
@php
    $unavailableBranch = $cardBranch?->name ?? ($freeDessert['branch_name'] ?? 'this branch');
    $savedStamps = $freeDessert['counted']
        ?? ($cardBranch ? \App\Services\FreeDesserts::countedDays(\Illuminate\Support\Facades\Auth::guard('customer')->user(), $cardBranch->id) : 0);
@endphp
<section id="free-dessert" class="scroll-mt-24 {{ $fillWidth ? 'card-surface w-full p-4 sm:p-5' : 'acct-card flex-1 p-4 sm:p-[22px]' }}" aria-labelledby="free-dessert-title" data-testid="stamp-card-unavailable" data-branch="{{ $cardBranch?->id }}">
    <div class="mb-3 flex {{ $fillWidth ? 'items-center' : 'items-start' }} gap-3">
        <span class="{{ $fillWidth ? 'grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-lg text-peach-red' : 'acct-icon text-lg' }}" aria-hidden="true">
            <i class="bi {{ $fillWidth ? 'bi-gift-fill' : 'bi-gift' }}"></i>
        </span>
        <div class="min-w-0">
            <h2 id="free-dessert-title" class="font-display text-lg font-bold {{ $fillWidth ? '' : 'leading-snug ' }}text-peach-deep">{{ $fillWidth ? 'Reward stamp card' : 'Reward Stamp Card' }}</h2>
            <p class="inline-flex items-center gap-1 {{ $fillWidth ? 'text-sm font-bold text-peach-red' : 'rounded-full border border-peach-soft bg-peach-soft/50 px-2 py-0.5 text-xs font-semibold text-peach-deep' }}" data-testid="free-dessert-branch">
                <i class="bi bi-shop" aria-hidden="true"></i> {{ $unavailableBranch }}
            </p>
        </div>
    </div>

    <p class="rounded-2xl bg-peach-soft/60 p-3 text-sm font-medium text-peach-deep" data-testid="stamp-card-unavailable-text">The stamp card isn't available at this branch yet.</p>

    @if($savedStamps > 0)
        <p class="mt-3 text-sm text-peach-deep/70" data-testid="stamp-card-saved">
            Your {{ $savedStamps }} {{ $savedStamps === 1 ? 'stamp' : 'stamps' }} at {{ $unavailableBranch }} {{ $savedStamps === 1 ? 'is' : 'are' }} saved.
        </p>
    @endif

    {{-- A full card that earned nothing yet, because the owner has not chosen
         a reward: it is added at the next completed order after one is. --}}
    @if(($freeDessert['waiting'] ?? 0) > 0)
        <p class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-800" data-testid="free-dessert-waiting">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            Your card is full! Your reward will be added to your account soon.
        </p>
    @endif
</section>

{{--
    Reward stamp card — shared by the Account page and Vouchers & Rewards
    (Oct 2026; the file keeps its first name, from when the reward was always
    a free dessert). Registered customers only.

    $freeDessert comes from App\Services\FreeDesserts::summaryFor(), via
    AuthController::showAccount() and ::showVouchers(), for ONE branch — the
    one the customer is ordering from — and the card is labeled with it. One
    stamp per shop day with a completed order at that branch (any days, not in
    a row; several orders on one day are one stamp). Every X stamps (that
    branch's App\Services\StreakRewardSettings) earn one reward — what it is
    comes from that branch's Streak Rewards settings — and a full card starts
    a new one, like a paper card. The card shows the stamps on
    the CURRENT card and how many rewards are ready; the rewards themselves,
    with their codes, are listed on Vouchers & Rewards ($rewardListHere is
    true there, so the card does not point at itself). Read-only: a reward is
    earned when staff complete an order and spent with its code at checkout.

    Look (Oct 2026 redesign): on the Account page ($fillWidth false) the card
    follows docs/design-refs/peachy-account-settings.html: the borderless
    20px card, the dashed stamp tray with tilted filled stamps, and a reward
    row under it. Vouchers & Rewards ($fillWidth true) keeps its own look.

    Phone sizing: text-sm (16px) and up for body text, text-xs (14px) only
    for helper text. Up to 7 stamps sit in one row and 8-12 in two, on a
    minmax(0,1fr) grid so nothing pushes the page sideways at 360px. More
    than FreeDesserts::MAX_STAMPS_SHOWN stamps get the progress bar instead.
--}}
@if($freeDessert['enabled'] ?? false)
@php
    $perReward = $freeDessert['visit_days'];
    $stamps = $freeDessert['progress'];
    $stampsLeft = $perReward - $stamps;
    $stampCols = $perReward <= 7 ? $perReward : (int) ceil($perReward / 2);
    $rewardText = $freeDessert['reward_text'] ?? null;
    // "a Free Chocolate Cake" for an item reward, "a reward: 20% off" for a voucher.
    $rewardPhrase = ! $rewardText ? 'a reward' : (str_starts_with($rewardText, 'Free ') ? 'a ' . $rewardText : 'a reward: ' . $rewardText);
    $readyCount = $freeDessert['available']->count();
    $rewardListHere = $rewardListHere ?? false;
    // Vouchers & Rewards stacks it with full-width blocks, so it fills the same
    // column and uses their padding; the Account page keeps its capped card.
    $fillWidth = $fillWidth ?? false;
    $cardBranch = $freeDessert['branch_name'] ?? 'this branch';
@endphp
<section id="free-dessert" class="scroll-mt-24 {{ $fillWidth ? 'card-surface w-full p-4 sm:p-5' : 'acct-card flex-1 p-4 sm:p-[22px]' }}" aria-labelledby="free-dessert-title" data-testid="free-dessert-card" data-branch="{{ $freeDessert['branch_id'] ?? '' }}">
    <div class="mb-4 flex {{ $fillWidth ? 'items-center' : 'items-start' }} gap-3">
        <span class="{{ $fillWidth ? 'grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-lg text-peach-red' : 'acct-icon text-lg' }}" aria-hidden="true">
            <i class="bi {{ $fillWidth ? 'bi-gift-fill' : 'bi-gift' }}"></i>
        </span>
        <div class="min-w-0">
            <h2 id="free-dessert-title" class="font-display text-lg font-bold {{ $fillWidth ? '' : 'leading-snug ' }}text-peach-deep">{{ $fillWidth ? 'Reward stamp card' : 'Reward Stamp Card' }}</h2>
            <p class="inline-flex items-center gap-1 {{ $fillWidth ? 'text-sm font-bold text-peach-red' : 'rounded-full border border-peach-soft bg-peach-soft/50 px-2 py-0.5 text-xs font-semibold text-peach-deep' }}" data-testid="free-dessert-branch">
                <i class="bi bi-shop" aria-hidden="true"></i> {{ $cardBranch }}
            </p>
            @if($fillWidth)
            <p class="text-sm text-peach-deep/60" data-testid="free-dessert-rule">
                Collect {{ $perReward }} stamps for a reward{{ $rewardText ? ': ' . $rewardText : '' }}. 1 stamp per day you completed an order at {{ $cardBranch }}.
            </p>
            @endif
        </div>
    </div>
    @unless($fillWidth)
        <p class="-mt-[7px] mb-3.5 ml-[52px] text-xs leading-normal text-peach-deep/70 max-[640px]:ml-0" data-testid="free-dessert-rule">
            Earn 1 stamp per day when you complete at least one order at {{ $cardBranch }}. Collect {{ $perReward }} stamps to receive {{ $rewardPhrase }}. The days do not need to be consecutive.
        </p>
    @endunless

    @unless($freeDessert['qualifies'])
        <p class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
            <i class="bi bi-envelope-exclamation"></i>
            Verify your email address to earn rewards.
        </p>
    @endunless

    {{-- The current card --}}
    <div class="{{ $fillWidth ? 'mb-4 rounded-2xl bg-peach-soft/60 px-3 py-3' : 'mb-3' }}">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <p class="text-sm {{ $fillWidth ? 'font-semibold text-peach-deep/80' : 'text-peach-deep/70' }}">Next reward</p>
            <p class="font-display text-xl {{ $fillWidth ? 'font-black' : 'font-bold' }} text-peach-red" data-testid="free-dessert-stamps">{{ $stamps }} / {{ $perReward }} stamps</p>
        </div>

        @if($freeDessert['show_stamps'])
            <ol class="mt-3 grid {{ $fillWidth ? 'gap-1.5 sm:gap-2' : 'gap-3 rounded-[18px] border-2 border-dashed border-peach-rose bg-peach-cream p-4' }}" style="grid-template-columns: repeat({{ $stampCols }}, minmax(0, 1fr));"
                aria-label="{{ $stamps }} of {{ $perReward }} stamps collected" data-testid="free-dessert-stamp-grid">
                @for($i = 1; $i <= $perReward; $i++)
                    @php $stamped = $i <= $stamps; $isPrize = $i === $perReward; @endphp
                    <li data-stamp="{{ $stamped ? 'filled' : 'empty' }}"
                        class="mx-auto grid aspect-square w-full {{ $fillWidth ? 'max-w-12' : 'max-w-16' }} place-items-center rounded-full text-sm {{ $fillWidth ? 'font-bold' : 'font-semibold' }}
                            {{ $stamped ? ($fillWidth ? 'bg-peach-red text-white shadow-sm' : '-rotate-[8deg] bg-peach-red text-white shadow-[0_6px_14px_-6px_var(--color-peach-red)]') : ($isPrize ? ($fillWidth ? 'border-2 border-dashed border-peach-red/50 bg-white text-peach-red' : 'border-2 border-solid border-peach bg-white text-peach-red') : 'border-2 border-dashed ' . ($fillWidth ? 'border-peach-deep/20' : 'border-peach-rose') . ' bg-white text-peach-deep/45') }}">
                        @if($stamped)
                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                        @elseif($isPrize)
                            <i class="bi bi-gift-fill" aria-hidden="true"></i>
                        @else
                            <span aria-hidden="true">{{ $i }}</span>
                        @endif
                        <span class="sr-only">Stamp {{ $i }}: {{ $stamped ? 'collected' : 'not yet' }}{{ $isPrize ? ', reward' : '' }}</span>
                    </li>
                @endfor
            </ol>
        @else
            <div class="mt-2 h-3 w-full overflow-hidden rounded-full bg-white" role="progressbar"
                 aria-valuemin="0" aria-valuemax="{{ $perReward }}" aria-valuenow="{{ $stamps }}"
                 aria-label="Stamps toward your next reward" data-testid="free-dessert-stamp-bar">
                <div class="h-full rounded-full bg-peach-red" style="width: {{ round($stamps / $perReward * 100) }}%"></div>
            </div>
        @endif

        @if($fillWidth)
        <p class="mt-2 text-sm text-peach-deep/70" data-testid="free-dessert-stamps-text">
            @if($freeDessert['counted'] === 0)
                Complete an order to collect your first stamp.
            @else
                {{ $stampsLeft }} more {{ $stampsLeft === 1 ? 'stamp' : 'stamps' }} to your next reward.
            @endif
        </p>
        @else
        <div class="mt-3.5 flex items-center gap-3 rounded-[14px] bg-peach-soft/60 p-3">
            <span class="acct-icon h-9 w-9 shrink-0 rounded-full text-base" aria-hidden="true"><i class="bi bi-gift-fill"></i></span>
            <p class="min-w-0 text-sm text-peach-deep" data-testid="free-dessert-stamps-text">
                @if($freeDessert['counted'] === 0)
                    Complete an order to collect your first stamp.
                @else
                    {{ $stampsLeft }} more {{ $stampsLeft === 1 ? 'stamp' : 'stamps' }} to your next reward.
                @endif
            </p>
        </div>
        @endif
    </div>

    {{-- A full card that earned nothing yet, because the owner has not chosen
         a reward: it is added at the next completed order after one is. --}}
    @if(($freeDessert['waiting'] ?? 0) > 0)
        <p class="mb-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-800" data-testid="free-dessert-waiting">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            Your card is full! Your reward will be added to your account soon.
        </p>
    @endif

    {{-- Ready to use: the codes live on Vouchers & Rewards. --}}
    @if($readyCount > 0)
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-green-200 bg-green-50 p-3" data-testid="free-dessert-available">
            <i class="bi bi-gift-fill text-lg text-green-700" aria-hidden="true"></i>
            <span class="min-w-0 flex-1 text-base font-bold text-peach-deep">
                {{ $readyCount === 1 ? '1 reward ready to use' : $readyCount . ' rewards ready to use' }} at {{ $cardBranch }}
            </span>
            @unless($rewardListHere)
                <a href="{{ route('customer.vouchers') }}#rewards"
                    class="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-peach-red px-4 py-2 text-sm font-bold text-white no-underline transition hover:bg-peach-deep">
                    See code <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            @endunless
        </div>
    @endif
</section>
@endif

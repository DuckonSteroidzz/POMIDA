{{-- Reward Code Checker — owner and supervisor, read-only; checking never
     redeems. The right-hand card on Streak Rewards. A supervisor gets details
     only for codes issued by their own branch: any other code answers exactly
     like one that does not exist (App\Services\RewardCodeCheck). No sample
     codes are shown, on purpose. --}}
<section class="content-card sr-checker" id="reward-code-check" style="scroll-margin-top:5rem;" data-testid="reward-code-check-card">
    <p class="sr-card-title">🔍 Reward Code Checker</p>
    <p class="sr-card-sub">
        Verify a customer's streak reward code. Checking never uses it.
        @unless($isAdmin)
            Only codes issued by your branch can be checked.
        @endunless
    </p>
    <hr class="sr-divider">

    @php $rcErrors = $errors->getBag(\App\Http\Controllers\Admin\StreakRewardController::ERROR_BAG_CHECK); @endphp
    <form action="{{ route('admin.streak.rewards.check') }}" method="POST" autocomplete="off" class="sr-check-row">
        @csrf
        <label class="visually-hidden" for="rewardCodeInput">Reward code</label>
        <input type="text" id="rewardCodeInput" name="reward_code" class="sr-input" required maxlength="40"
            placeholder="Enter reward code" autocomplete="off" spellcheck="false">
        <button type="submit" class="btn-primary-custom"><i class="bi bi-search" aria-hidden="true"></i> Check</button>
    </form>
    @if($rcErrors->has('reward_code'))<span class="sr-error">{{ $rcErrors->first('reward_code') }}</span>@endif

    @if(is_array($check = session('reward_check')))
        @php
            $checkBadge = match ($check['state'] ?? '') {
                'valid'    => ['bi-check-circle-fill', 'Valid — can be used', '#E4F2EA', '#2E7D5B'],
                'used'     => ['bi-x-circle-fill', 'Already used', '#FDECEC', '#B91C1C'],
                'expired'  => ['bi-clock-history', 'Expired', '#F3F4F6', '#4B5563'],
                'not_set'  => ['bi-hourglass-split', 'Not ready yet', '#FFFBEB', '#92400E'],
                default    => ['bi-question-circle-fill', 'Not found', '#F3F4F6', '#4B5563'],
            };
        @endphp
        <div class="sr-result" data-testid="reward-check-result" data-state="{{ $check['state'] ?? 'not_found' }}">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.6rem;margin-bottom:0.6rem;">
                <span class="sr-badge" style="background:{{ $checkBadge[2] }};color:{{ $checkBadge[3] }};font-size:0.76rem;">
                    <i class="bi {{ $checkBadge[0] }}"></i> {{ $checkBadge[1] }}
                </span>
                <code style="font-size:0.92rem;font-weight:800;letter-spacing:0.08em;color:var(--pc-maroon);overflow-wrap:anywhere;">{{ $check['code'] ?? '' }}</code>
            </div>

            <div class="sr-result-box">
                @if(($check['state'] ?? '') === 'not_found')
                    <p style="font-size:0.8rem;color:#4B5563;margin:0;">No reward has this code. Check it was typed correctly.</p>
                @else
                    <dl class="sr-dl">
                        <dt>Customer</dt><dd>{{ $check['customer'] }}</dd>
                        <dt>Reward</dt><dd>{{ $check['reward'] }}</dd>
                        <dt>Issued by</dt><dd data-testid="reward-check-issued-by">{{ $check['issued_by'] ?? '—' }}</dd>
                        <dt>Earned</dt><dd>{{ $check['earned'] }}</dd>
                        <dt>Expires</dt><dd>{{ $check['expires'] }}</dd>
                        @if(($check['state'] ?? '') === 'used')
                            <dt>Order</dt><dd>{{ $check['order_number'] ?? 'Not on record' }}</dd>
                            <dt>Used on</dt><dd>{{ $check['used_on'] ?? '—' }}</dd>
                            <dt>Branch</dt><dd>{{ $check['branch'] ?? '—' }}</dd>
                        @endif
                    </dl>
                    @if(! empty($check['order_open']))
                        <p style="font-size:0.76rem;color:#4B5563;margin:0.6rem 0 0;">That order is not finished yet. If it is cancelled, the reward goes back to the customer with the same code.</p>
                    @endif
                @endif
            </div>
        </div>
    @endif
</section>

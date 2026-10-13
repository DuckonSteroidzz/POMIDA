{{-- Check a reward code — owner and supervisor, read-only; never redeems.
     Always rendered near the top of the page, above the branch tabs and
     settings card, as its own full-width card — independent of whether
     the viewer has a branch scope to edit below it. --}}
<div class="content-card" id="reward-code-check" style="scroll-margin-top:5rem;" data-testid="reward-code-check-card">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin:0;">🔎 Check a reward code</p>
    <p style="font-size:0.78rem;color:#888;margin:0 0 0.85rem;">
        See whether a customer's reward code is valid. Checking never uses it.
        @unless($isAdmin)
            You see full details only for codes issued by your branch.
        @endunless
    </p>

    @php $rcErrors = $errors->getBag(\App\Http\Controllers\Admin\StreakRewardController::ERROR_BAG_CHECK); @endphp
    <form action="{{ route('admin.streak.rewards.check') }}" method="POST" autocomplete="off" class="sr-check-row">
        @csrf
        <div class="sr-check-field sr-narrow">
            <label class="form-label-custom" for="rewardCodeInput">Reward code</label>
            <input type="text" id="rewardCodeInput" name="reward_code" class="form-control-custom" required maxlength="40"
                autocomplete="off" spellcheck="false" style="text-transform:uppercase;letter-spacing:0.08em;">
        </div>
        <button type="submit" class="btn-primary-custom" style="min-height:44px;"><i class="bi bi-search"></i> Check</button>
    </form>
    @if($rcErrors->has('reward_code'))<span class="sr-error">{{ $rcErrors->first('reward_code') }}</span>@endif

    @if(is_array($check = session('reward_check')))
        @php
            $checkBadge = match ($check['state'] ?? '') {
                'valid'    => ['bi-check-circle-fill', 'Valid — can be used', '#E4F2EA', '#2E7D5B'],
                'used'     => ['bi-x-circle-fill', 'Already used', '#FDECEC', '#B91C1C'],
                'expired'  => ['bi-clock-history', 'Expired', '#F3F4F6', '#4B5563'],
                'not_set'  => ['bi-hourglass-split', 'Not ready yet', '#FFFBEB', '#92400E'],
                'other_branch' => ['bi-shop', 'Another branch', '#F3F4F6', '#4B5563'],
                default    => ['bi-question-circle-fill', 'Not found', '#F3F4F6', '#4B5563'],
            };
        @endphp
        <div class="sr-result" data-testid="reward-check-result" data-state="{{ $check['state'] ?? 'not_found' }}">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.6rem;margin-bottom:0.6rem;">
                <span class="sr-badge" style="background:{{ $checkBadge[2] }};color:{{ $checkBadge[3] }};">
                    <i class="bi {{ $checkBadge[0] }}"></i> {{ $checkBadge[1] }}
                </span>
                <code style="font-size:0.95rem;font-weight:800;letter-spacing:0.08em;color:#8B1A1A;">{{ $check['code'] ?? '' }}</code>
            </div>

            @if(($check['state'] ?? '') === 'not_found')
                <p style="font-size:0.82rem;color:#4B5563;margin:0;">No reward has this code. Check it was typed correctly (codes look like RWD-XXXX-XXXX).</p>
            @elseif(! empty($check['restricted']))
                <p style="font-size:0.82rem;color:#4B5563;margin:0;" data-testid="reward-check-restricted">This code belongs to another branch.</p>
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
                    <p style="font-size:0.78rem;color:#4B5563;margin:0.6rem 0 0;">That order is not finished yet. If it is cancelled, the reward goes back to the customer with the same code.</p>
                @endif
            @endif
        </div>
    @endif
</div>

@extends('admin.layout')

@section('title', 'Streak Rewards - Peachy Admin')

@section('content')

{{-- Streak Rewards (Oct 2026) — split out of Vouchers & Game into its own
     simple page. Single-column stack, top to bottom: the Streak Calendar
     switch (admin only), the "Check a reward code" card (admin + supervisor,
     always at the top — before the branch picker, so it's reachable without
     scrolling past the settings form), the branch tabs kept directly above
     the settings card they control, and the "Every branch" overview table
     last (admin only). Inside the settings card: On/Off, Days needed +
     Valid for side by side, Reward, Save, with one short help line per
     field instead of long paragraphs.

     Admin: a master on/off switch, a branch picker ("All branches" + every
     branch) and a small overview table of every branch.
     Supervisor: locked to their own branch — no switch, no picker, no
     overview table. A branchless supervisor sees only the warning card in
     place of the tabs+settings card (the check card above it still works).

     Presentation only — the routes are the real boundary. See
     App\Http\Controllers\Admin\StreakRewardController::targetBranch() and
     App\Http\Controllers\Admin\StreakRewardController::index(). --}}

<style>
    .sr-help{display:block;font-size:0.75rem;color:#4B5563;margin-top:0.25rem;line-height:1.4}
    .sr-error{display:block;color:#B91C1C;font-size:0.75rem;margin-top:0.25rem;font-weight:600}
    .sr-warn{font-size:0.82rem;line-height:1.45;color:#92400E;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:0.6rem 0.85rem;margin:0 0 1rem}
    .sr-field{margin-bottom:1rem}
    .sr-types{display:flex;flex-wrap:wrap;gap:0.5rem;margin:0.25rem 0 0}
    .sr-type{display:flex;align-items:center;gap:0.5rem;min-height:44px;padding:0 0.9rem;border:1.5px solid #F3D9CF;border-radius:10px;background:#fff;cursor:pointer;font-size:0.85rem;font-weight:600;color:#333}
    .sr-type:has(input:disabled){opacity:0.5;cursor:not-allowed}
    .sr-type input{width:18px;height:18px;accent-color:#F4845F;margin:0}
    .sr-dl{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:0.4rem 1rem;margin:0;font-size:0.85rem}
    .sr-dl dt{color:#8A6A61;font-weight:600}
    .sr-dl dd{margin:0;color:#333;font-weight:600;overflow-wrap:anywhere}
    .sr-check-row{display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap}
    .sr-check-row .sr-check-field{flex:1 1 220px;min-width:0}
    .sr-badge{display:inline-flex;align-items:center;gap:0.35rem;border-radius:999px;padding:0.3rem 0.8rem;font-size:0.8rem;font-weight:800}
    .sr-result{margin-top:1rem;border:1px solid #F0E2D5;border-radius:12px;padding:0.85rem 1rem;background:#FFFBF7}
    .sr-tabs{display:flex;flex-wrap:wrap;gap:0.4rem;margin:0 0 1rem}
    .sr-tab{display:inline-flex;align-items:center;min-height:44px;padding:0 1rem;border:1.5px solid #F3D9CF;border-radius:999px;background:#fff;color:#5B4740;font-size:0.85rem;font-weight:700;text-decoration:none}
    .sr-tab:hover{border-color:#F4845F;color:#C0392B}
    .sr-tab.is-active{background:linear-gradient(135deg,#F4845F,#EF8585);color:#fff;border-color:transparent}
    .sr-table-wrap{overflow-x:auto;margin:0;border:1px solid #F0E2D5;border-radius:12px}
    .sr-table{width:100%;border-collapse:collapse;font-size:0.82rem}
    .sr-table th,.sr-table td{text-align:left;padding:0.55rem 0.7rem;border-bottom:1px solid #F0E2D5;vertical-align:top}
    .sr-table tr:last-child td{border-bottom:0}
    .sr-table th{color:#8A6A61;font-weight:700;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;background:#FFFBF7}
    .sr-table a{color:#C0392B;font-weight:700;text-decoration:none}
    .sr-pill{display:inline-block;border-radius:999px;padding:0.15rem 0.6rem;font-size:0.75rem;font-weight:800;white-space:nowrap}
    .sr-pill-on{background:#E4F2EA;color:#2E7D5B}
    .sr-pill-off{background:#F3F4F6;color:#4B5563}
    .sr-card-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.6rem;margin-bottom:0.85rem}
    .sr-card-title{font-size:0.95rem;font-weight:800;color:#8B1A1A;margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem}
    .sr-switch{min-height:44px;border:none;border-radius:22px;padding:0 1.2rem;font-size:0.82rem;font-weight:700;color:#fff;cursor:pointer;font-family:'Poppins',sans-serif}
    .sr-btn-ghost{min-height:44px;background:#fff;color:#C0392B;border:1.5px solid #F6B49B;border-radius:8px;padding:0 1rem;font-size:0.85rem;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif}
    .sr-btn-ghost:hover{background:#fff4ec}
    .sr-onoff-row{display:flex;align-items:center;gap:0.7rem;flex-wrap:wrap}
    .sr-onoff-state{font-weight:800;font-size:0.85rem}
    .sr-apply{display:flex;align-items:flex-start;gap:0.55rem;min-height:44px;margin:0.25rem 0 1rem;font-size:0.82rem;color:#333;cursor:pointer}
    .sr-apply input{width:16px;height:16px;margin-top:0.2rem;accent-color:#F4845F;flex-shrink:0}
    .sr-narrow{max-width:400px}
    .sr-pair{display:flex;flex-wrap:wrap;gap:0.75rem;max-width:400px;margin-bottom:1rem}
    .sr-pair > .sr-field{flex:1 1 160px;min-width:140px;margin-bottom:0}
</style>

@if($streakRewards['scope_ok'] && ! $isAdmin && $streakRewards['branch'])
    <p class="page-title">Streak Rewards - {{ $streakRewards['branch']->name }}</p>
@else
    <p class="page-title">Streak Rewards</p>
@endif
<p style="font-size:0.82rem;color:#888;margin:-0.5rem 0 1rem;">Set what a full stamp card gives, and turn it on or off.</p>

{{-- Admin-only master switch: the Spin Wheel card's twin, same tier
     (admin.streak.toggle stays in `role:admin`). On when no row exists —
     see Setting::streakCalendarEnabled(). Off stops NEW rewards and hides
     the stamp card everywhere; rewards already earned stay usable. --}}
@if($isAdmin)
<div class="content-card" style="margin-bottom:1rem;" data-testid="streak-switch-card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">
        <div>
            <p style="font-size:0.9rem;font-weight:700;color:#333;margin:0;">📅 Streak Calendar on/off</p>
            <p style="font-size:0.78rem;color:#888;margin:0;">Turns the stamp card off for every branch. Already-earned rewards still work.</p>
        </div>
        <form action="{{ route('admin.streak.toggle') }}" method="POST" style="margin:0;flex-shrink:0;">
            @csrf
            <button type="submit"
                style="background:{{ $streakRewards['master_enabled'] ? '#4CAF50' : '#ccc' }};color:white;border:none;border-radius:20px;padding:0.4rem 1.2rem;font-size:0.78rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
                {{ $streakRewards['master_enabled'] ? '✓ Enabled' : '✗ Disabled' }}
            </button>
        </form>
    </div>
</div>
@endif

@include('admin.partials.streak-reward-code-check')

@if(! $streakRewards['scope_ok'])
    <div class="content-card" data-testid="streak-rewards-no-branch">
        <p class="sr-warn" style="margin:0;">
            <i class="bi bi-exclamation-triangle"></i>
            Your account is not assigned to a branch, so there are no streak reward settings for you to change.
        </p>
    </div>
@else
    @php
        $srBranch = $streakRewards['branch'];
        $srIsDefault = $srBranch === null;
        $srBranchName = $srBranch?->name;
        $srScopeValue = $srIsDefault ? \App\Http\Controllers\Admin\StreakRewardController::DEFAULT_SCOPE : (string) $srBranch->id;
        $srProblem = \App\Services\StreakRewardSettings::problemText($streakRewards, $srBranchName);
        $srErrors = $errors->getBag(\App\Http\Controllers\Admin\StreakRewardController::ERROR_BAG_SETTINGS);
        $srType = old('reward_type', $streakRewards['type'] ?? \App\Services\StreakRewardSettings::TYPE_VOUCHER);

        // "All branches" can never give a free menu item — no menu item is
        // ever company-wide in this business (see the comment on
        // $rewardMenuItemOptions in StreakRewardController::index()) — so
        // never default the form into a type nobody here can actually save.
        if ($srIsDefault && $srType === \App\Services\StreakRewardSettings::TYPE_MENU_ITEM) {
            $srType = \App\Services\StreakRewardSettings::TYPE_VOUCHER;
        }

        $srVoucherId = (string) old('voucher_id', $streakRewards['voucher']['voucher_id'] ?? '');
        $srMenuItemId = (string) old('menu_item_id', $streakRewards['menu_item_id'] ?? '');
    @endphp

    {{-- Branch picker — owner only. "All branches" first, then every
         branch, each a plain link so the page reloads with ?streak_branch=. --}}
    @if($isAdmin)
        <nav class="sr-tabs" aria-label="Choose the branch to edit" data-testid="streak-rewards-selector">
            <a class="sr-tab {{ $srIsDefault ? 'is-active' : '' }}" @if($srIsDefault) aria-current="page" @endif
                href="{{ route('admin.streak-rewards', ['streak_branch' => \App\Http\Controllers\Admin\StreakRewardController::DEFAULT_SCOPE]) }}#streak-rewards-card">All branches</a>
            @foreach($streakOverview as $srRow)
                @php $srTabActive = ! $srIsDefault && $srRow['branch']->id === $srBranch->id; @endphp
                <a class="sr-tab {{ $srTabActive ? 'is-active' : '' }}" @if($srTabActive) aria-current="page" @endif
                    href="{{ route('admin.streak-rewards', ['streak_branch' => $srRow['branch']->id]) }}#streak-rewards-card">{{ $srRow['branch']->name }}</a>
            @endforeach
        </nav>
    @endif

    <div class="content-card" id="streak-rewards-card" style="scroll-margin-top:5rem;" data-testid="streak-rewards-card" data-scope="{{ $srScopeValue }}">
        <div class="sr-card-head">
            <p class="sr-card-title">
                🎁 {{ $srIsDefault ? 'All branches' : $srBranchName }}
                @unless($srIsDefault)
                    <span class="sr-pill {{ $streakRewards['own'] ? 'sr-pill-on' : 'sr-pill-off' }}" data-testid="streak-rewards-source-pill">{{ $streakRewards['own'] ? 'Own settings' : 'Uses All branches' }}</span>
                @endunless
            </p>
            @if(! $srIsDefault && $streakRewards['own'])
                <form action="{{ route('admin.streak.rewards.default') }}" method="POST" style="margin:0;" data-testid="streak-rewards-source">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $srBranch->id }}">
                    <button type="submit" class="sr-btn-ghost"><i class="bi bi-arrow-counterclockwise"></i> Use All branches instead</button>
                </form>
            @endif
        </div>

        @if(! $streakRewards['master_enabled'])
            <p class="sr-warn" data-testid="streak-rewards-master-off">
                <i class="bi bi-pause-circle"></i>
                @if($isAdmin)
                    The switch above is off, so nothing is given anywhere right now.
                @else
                    The owner turned the Streak Calendar off, so nothing is given right now.
                @endif
            </p>
        @endif

        <form action="{{ route('admin.streak.rewards.update') }}" method="POST" autocomplete="off" data-testid="streak-rewards-form">
            @csrf
            <input type="hidden" name="branch_id" value="{{ $srScopeValue }}">

            {{-- On/Off — a branch's own switch. "All branches" has no switch
                 of its own; the master switch above already covers it. --}}
            @unless($srIsDefault)
                <div class="sr-field" data-testid="streak-branch-switch">
                    <label class="form-label-custom">On/Off</label>
                    <div class="sr-onoff-row">
                        <span class="sr-onoff-state" style="color:{{ $streakRewards['branch_enabled'] ? '#2E7D5B' : '#8A6A61' }};" data-testid="streak-branch-switch-state">{{ $streakRewards['branch_enabled'] ? 'On' : 'Off' }}</span>
                        <button type="submit" formaction="{{ route('admin.streak.branch.toggle') }}" formnovalidate class="sr-switch" style="background:{{ $streakRewards['branch_enabled'] ? '#4CAF50' : '#9CA3AF' }};">
                            {{ $streakRewards['branch_enabled'] ? '✓ Enabled' : '✗ Disabled' }}
                        </button>
                    </div>
                    <span class="sr-help">Turns the stamp card off at {{ $srBranchName }} only.</span>
                </div>
            @endunless

            {{-- Days needed + Valid for — side by side while there's room,
                 wrapping to stacked rows once the column gets narrow
                 (the two-column desktop layout, or a phone). --}}
            <div class="sr-pair">
                <div class="sr-field">
                    <label class="form-label-custom" for="srDays">Days needed *</label>
                    <input type="number" id="srDays" name="days" class="form-control-custom" required
                        min="1" max="{{ \App\Services\StreakRewardSettings::MAX_DAYS }}" step="1"
                        value="{{ old('days', $streakRewards['days'] > 0 ? $streakRewards['days'] : 7) }}">
                    <span class="sr-help">How many different days with a completed order earn one reward.</span>
                    @if($srErrors->has('days'))<span class="sr-error">{{ $srErrors->first('days') }}</span>@endif
                </div>

                <div class="sr-field">
                    <label class="form-label-custom" for="srValidDays">Valid for (days) *</label>
                    <input type="number" id="srValidDays" name="valid_days" class="form-control-custom" required
                        min="1" max="{{ \App\Services\StreakRewardSettings::MAX_VALID_DAYS }}" step="1"
                        value="{{ old('valid_days', $streakRewards['valid_days'] > 0 ? $streakRewards['valid_days'] : 30) }}">
                    <span class="sr-help">How many days a reward can be used after it's earned.</span>
                    @if($srErrors->has('valid_days'))<span class="sr-error">{{ $srErrors->first('valid_days') }}</span>@endif
                </div>
            </div>

            @if($srProblem)
                <p class="sr-warn" data-testid="streak-rewards-not-set">
                    <i class="bi bi-exclamation-triangle"></i> {{ $srProblem }}
                </p>
            @endif

            {{-- Reward --}}
            <fieldset style="border:0;padding:0;margin:0 0 0.35rem;">
                <legend class="form-label-custom">Reward *</legend>
                <div class="sr-types">
                    @unless($srIsDefault)
                        <label class="sr-type">
                            <input type="radio" name="reward_type" value="{{ \App\Services\StreakRewardSettings::TYPE_MENU_ITEM }}" data-sr-type
                                @checked($srType === \App\Services\StreakRewardSettings::TYPE_MENU_ITEM)>
                            Free menu item
                        </label>
                    @endunless
                    <label class="sr-type">
                        <input type="radio" name="reward_type" value="{{ \App\Services\StreakRewardSettings::TYPE_VOUCHER }}" data-sr-type
                            @checked($srType === \App\Services\StreakRewardSettings::TYPE_VOUCHER)>
                        Voucher discount
                    </label>
                </div>
                @if($srIsDefault)
                    <span class="sr-help" data-testid="streak-rewards-item-per-branch">For a free menu item, choose a branch above.</span>
                @endif
                @if($srErrors->has('reward_type'))<span class="sr-error">{{ $srErrors->first('reward_type') }}</span>@endif
            </fieldset>

            {{-- Picker: menu item (branch scope only — see above) --}}
            @unless($srIsDefault)
            <div data-sr-panel="{{ \App\Services\StreakRewardSettings::TYPE_MENU_ITEM }}" class="sr-field"
                @if($srType !== \App\Services\StreakRewardSettings::TYPE_MENU_ITEM) hidden @endif>
                <label class="form-label-custom" for="srMenuItem">Menu item</label>
                @if($rewardMenuItemOptions->isEmpty())
                    <p class="sr-help" style="margin-top:0;" data-testid="streak-rewards-no-items">No menu items at {{ $srBranchName }} yet. Add one in Menu Items first.</p>
                @else
                    <select id="srMenuItem" name="menu_item_id" class="form-control-custom sr-narrow">
                        <option value="">Choose a menu item</option>
                        @foreach($rewardMenuItemOptions as $option)
                            <option value="{{ $option->id }}" @selected($srMenuItemId === (string) $option->id)>
                                {{ $option->name }}{{ $option->is_available ? '' : ' (switched off now)' }}
                            </option>
                        @endforeach
                    </select>
                    <span class="sr-help">Given as one free serving, while it can still be ordered.</span>
                @endif
                @if($srErrors->has('menu_item_id'))<span class="sr-error">{{ $srErrors->first('menu_item_id') }}</span>@endif
            </div>
            @endunless

            {{-- Picker: voucher --}}
            <div data-sr-panel="{{ \App\Services\StreakRewardSettings::TYPE_VOUCHER }}" class="sr-field"
                @if(! $srIsDefault && $srType !== \App\Services\StreakRewardSettings::TYPE_VOUCHER) hidden @endif>
                <label class="form-label-custom" for="srVoucher">Voucher</label>
                @if($rewardVoucherOptions->isEmpty())
                    <p class="sr-help" style="margin-top:0;" data-testid="streak-rewards-no-vouchers">Create a voucher first in <a href="{{ route('admin.vouchers') }}" style="color:#C0392B;font-weight:700;">Vouchers</a>.</p>
                @else
                    <select id="srVoucher" name="voucher_id" class="form-control-custom sr-narrow">
                        <option value="">Choose a voucher</option>
                        @foreach($rewardVoucherOptions as $option)
                            <option value="{{ $option->id }}" @selected($srVoucherId === (string) $option->id)>
                                {{ $option->code }} — {{ $option->discount_type === 'percent' ? rtrim(rtrim(number_format((float) $option->discount_value, 2, '.', ''), '0'), '.') . '% off' : '₱' . number_format((float) $option->discount_value, 2) . ' off' }}{{ (float) $option->minimum_order > 0 ? ', min. ₱' . number_format((float) $option->minimum_order, 2) : '' }}
                            </option>
                        @endforeach
                    </select>
                    <span class="sr-help">{{ $srIsDefault ? 'Only vouchers for all branches work here.' : 'Vouchers for ' . $srBranchName . ' or for all branches.' }} Copied when you save; editing the voucher later won't change it.</span>
                @endif
                @if($srErrors->has('voucher_id'))<span class="sr-error">{{ $srErrors->first('voucher_id') }}</span>@endif
            </div>

            @if($srIsDefault)
                <label class="sr-apply" data-testid="streak-rewards-apply-all">
                    <input type="checkbox" name="apply_to_all" value="1" @checked(old('apply_to_all'))>
                    <span>Also make all branches use this (removes each branch's own settings).</span>
                </label>
            @endif

            <button type="submit" class="btn-primary-custom" style="min-height:44px;"><i class="bi bi-check2-circle"></i> {{ $srIsDefault ? 'Save for all branches' : 'Save for ' . $srBranchName }}</button>
            <p style="font-size:0.75rem;color:#8A6A61;margin:0.6rem 0 0;">Rewards already earned are not changed.</p>
        </form>

        <script>
            (function () {
                var radios = document.querySelectorAll('[data-sr-type]');
                var panels = document.querySelectorAll('[data-sr-panel]');

                function sync() {
                    var picked = document.querySelector('[data-sr-type]:checked');
                    panels.forEach(function (panel) {
                        panel.hidden = !picked || panel.getAttribute('data-sr-panel') !== picked.value;
                    });
                }

                radios.forEach(function (radio) { radio.addEventListener('change', sync); });
                sync();
            })();
        </script>
    </div>

    {{-- Owner-only overview: every branch at a glance. "On" means it is
         really on for customers: the master switch AND the branch's own.
         Last on the page. --}}
    @if($isAdmin)
        <div class="content-card" style="margin-bottom:1rem;">
            <p style="font-size:0.9rem;font-weight:700;color:#333;margin:0 0 0.75rem;">Every branch</p>
            <div class="sr-table-wrap">
                <table class="sr-table" data-testid="streak-rewards-overview">
                    <thead>
                        <tr><th scope="col">Branch</th><th scope="col">On/Off</th><th scope="col">Days</th><th scope="col">Reward</th></tr>
                    </thead>
                    <tbody>
                        @foreach($streakOverview as $srRow)
                            @php
                                $srRowCurrent = $srRow['current'];
                                $srRowOn = $srRow['enabled'] && $streakRewards['master_enabled'];
                            @endphp
                            <tr data-branch-row="{{ $srRow['branch']->id }}">
                                <td><a href="{{ route('admin.streak-rewards', ['streak_branch' => $srRow['branch']->id]) }}#streak-rewards-card">{{ $srRow['branch']->name }}</a></td>
                                <td><span class="sr-pill {{ $srRowOn ? 'sr-pill-on' : 'sr-pill-off' }}">{{ $srRowOn ? 'On' : 'Off' }}</span></td>
                                <td>{{ $srRowCurrent['days'] > 0 ? $srRowCurrent['days'] : '—' }}</td>
                                <td>{{ $srRowCurrent['description'] ?? 'None' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endif

@endsection

@extends('admin.layout')

@section('title', 'Streak Rewards - Peachy Admin')

@section('content')

{{-- Streak Rewards (Oct 2026), rebuilt to the tester's design
     (docs/design-refs/peachy-streak-rewards.html).

     Top to bottom: the Master Streak Calendar bar (owner only), then two
     columns that stack on a narrow screen — the settings card on the left
     (branch tabs, On/Off, Days needed + Valid for, Reward, Voucher or menu
     item, "Also make all branches use this", the stamp preview, Save) and on
     the right the Reward Code Checker and the Branch Overview (owner only,
     three rows a page). The design's "Hide Branch Overview" button was a demo
     control and is deliberately not built.

     Owner: every branch. The branch tabs, the "Viewing:" bar at the top of
     the page and every other admin page share ONE choice
     (App\Support\ViewingBranch), so they never disagree.
     Supervisor: locked to their own branch — no master switch, no tabs, no
     overview; the bar shows their branch as a fixed label. A branchless
     supervisor sees only the warning card and the code checker.

     Presentation only — the routes are the real boundary. See
     StreakRewardController::targetBranch() and ::index(). --}}

<style>
    .sr-master{display:flex;align-items:center;gap:0.9rem;background:linear-gradient(90deg,#FDE3CD,#FBD8BF);border:1px solid #F6CBAE;border-radius:var(--pc-radius);padding:0.95rem 1.2rem;margin:0 0 1rem;box-shadow:var(--pc-shadow)}
    .sr-master-icon{font-size:1.35rem;line-height:1;flex:0 0 auto}
    .sr-master-text{flex:1 1 auto;min-width:0}
    .sr-master-title{font-size:1rem;font-weight:800;color:var(--pc-maroon);margin:0}
    .sr-master-sub{font-size:0.8rem;color:var(--pc-mute);margin:0.15rem 0 0}
    .sr-master form{margin:0;flex:0 0 auto;display:flex;align-items:center;gap:0.5rem}

    /* A real button that looks like a switch: each one submits its form. */
    .sr-switch{position:relative;display:inline-block;flex:0 0 auto;width:46px;height:26px;padding:0;border:0;border-radius:999px;background:#E7C4B4;cursor:pointer;transition:background .18s ease}
    .sr-switch::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .18s ease}
    .sr-switch.is-on{background:var(--pc-peach)}
    .sr-switch.is-on::after{transform:translateX(20px)}
    .sr-switch:focus-visible{outline:3px solid rgba(244,132,95,.5);outline-offset:2px}
    .sr-switch.is-readonly{cursor:default;opacity:0.6}
    .sr-switch.is-sm{width:40px;height:23px}
    .sr-switch.is-sm::after{width:17px;height:17px}
    .sr-switch.is-sm.is-on::after{transform:translateX(17px)}
    .sr-switch-state{font-size:0.78rem;font-weight:800;color:var(--pc-mute);min-width:1.6rem}
    .sr-switch-state.is-on{color:#2E7D5B}

    .sr-main{display:grid;grid-template-columns:minmax(0,7fr) minmax(0,5fr);gap:1.1rem;align-items:stretch}
    @media (max-width:1100px){.sr-main{grid-template-columns:minmax(0,1fr)}}
    .sr-main > .content-card, .sr-side > .content-card{margin-bottom:0}
    .sr-side{display:flex;flex-direction:column;gap:1.1rem;min-width:0}
    .sr-side .sr-checker{flex:1 1 auto}

    .sr-card-title{font-size:1rem;font-weight:800;color:var(--pc-maroon);margin:0}
    .sr-card-sub{font-size:0.8rem;color:var(--pc-mute);margin:0.2rem 0 0}
    .sr-divider{border:0;border-top:1px dashed #F3DCCD;margin:0.95rem 0;opacity:1}

    /* Branch tabs: the page's own tab bar component (.promo-tabs, from the
       Promotions & Rewards header) — only spacing is added here. */
    .sr-branch-tabs{margin:0 0 0.95rem}

    .sr-scope-line{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.5rem;margin:0 0 0.85rem}
    .sr-scope-line form{margin:0}
    .sr-badge{display:inline-flex;align-items:center;gap:0.3rem;border-radius:999px;padding:0.2rem 0.65rem;font-size:0.7rem;font-weight:800;letter-spacing:0.02em;white-space:nowrap}
    .sr-badge.is-override{background:#FDF0E8;color:var(--pc-red)}
    .sr-badge.is-defaults{background:#FDF3E3;color:#A05E12}
    .sr-badge.is-on{background:#E8F6EC;color:#1D7A3E}
    .sr-badge.is-off{background:#F3F4F6;color:#4B5563}
    .sr-btn-ghost{display:inline-flex;align-items:center;gap:0.35rem;min-height:36px;background:#fff;color:var(--pc-red);border:1.5px solid #F6B49B;border-radius:999px;padding:0 0.85rem;font-size:0.78rem;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
    .sr-btn-ghost:hover{background:#FFF4EC;color:var(--pc-red)}

    .sr-warn{font-size:0.8rem;line-height:1.45;color:#92400E;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:0.6rem 0.85rem;margin:0 0 0.9rem}

    .sr-setrow{display:flex;align-items:center;gap:0.8rem;background:#FDF0E8;border-radius:12px;padding:0.75rem 1rem;margin:0 0 1rem}
    .sr-setrow-text{flex:1 1 auto;min-width:0}
    .sr-setrow-text b{display:block;font-size:0.88rem;color:var(--pc-cocoa)}
    .sr-setrow-text span{display:block;font-size:0.76rem;color:var(--pc-mute);margin-top:0.1rem}

    .sr-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:0.9rem;margin:0 0 0.4rem}
    @media (max-width:520px){.sr-fields{grid-template-columns:minmax(0,1fr)}}
    .sr-label{display:block;font-size:0.8rem;font-weight:800;color:var(--pc-cocoa);margin:0 0 0.4rem}
    .sr-label em{color:var(--pc-red);font-style:normal}
    .sr-input{width:100%;border:1px solid #F3DCCD;border-radius:10px;padding:0.6rem 0.8rem;font-size:0.88rem;background:#fff;color:var(--pc-cocoa);font-family:inherit;outline:none}
    .sr-input:focus{border-color:var(--pc-peach);box-shadow:0 0 0 3px rgba(244,132,95,.18)}
    .sr-help{display:block;font-size:0.74rem;color:var(--pc-mute);margin-top:0.35rem;line-height:1.45}
    .sr-error{display:block;color:#B91C1C;font-size:0.75rem;margin-top:0.3rem;font-weight:700}
    .sr-field{margin:0.9rem 0 0}

    /* Reward: two real radios drawn as a segmented control. */
    .sr-reward{border:0;padding:0;margin:0.9rem 0 0;min-width:0}
    .sr-seg{display:flex;background:#FDF0E8;border-radius:12px;padding:4px;gap:4px}
    .sr-seg-opt{flex:1 1 0;min-width:0;position:relative;display:flex;align-items:center;justify-content:center;gap:0.35rem;min-height:40px;padding:0 0.6rem;border-radius:9px;font-size:0.82rem;font-weight:700;color:var(--pc-mute);cursor:pointer;text-align:center;transition:background .15s,color .15s;margin:0}
    .sr-seg-opt input{position:absolute;opacity:0;width:1px;height:1px;margin:0;pointer-events:none}
    .sr-seg-opt:has(input:checked){background:#fff;color:var(--pc-red);box-shadow:0 1px 4px rgba(0,0,0,.08)}
    .sr-seg-opt:has(input:focus-visible){outline:3px solid rgba(244,132,95,.5);outline-offset:1px}

    .sr-perbranch{background:#FFF8F2;border:1px dashed #F0B59D;border-radius:12px;padding:0.75rem 0.9rem}
    .sr-perbranch p{font-size:0.8rem;color:var(--pc-cocoa);margin:0 0 0.6rem;line-height:1.45}
    .sr-perbranch-links{display:flex;flex-wrap:wrap;gap:0.4rem}

    .sr-apply{display:flex;align-items:flex-start;gap:0.55rem;margin:0.95rem 0 0;font-size:0.82rem;font-weight:700;color:var(--pc-cocoa);cursor:pointer}
    .sr-apply input{width:16px;height:16px;margin-top:0.15rem;accent-color:var(--pc-red);flex-shrink:0}
    .sr-fineprint{font-size:0.74rem;color:var(--pc-mute);margin:0.5rem 0 0}

    .sr-stamps{display:flex;flex-wrap:wrap;gap:7px;margin:0.95rem 0 0}
    .sr-stamp{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-size:0.78rem;font-weight:800;background:var(--pc-peach);color:#fff}
    .sr-stamp.is-empty{background:#fff;border:1.5px dashed #F0B59D;color:#E8B39E}
    .sr-stamp.is-gift{background:#fff;border:1.5px dashed #F0B59D;font-size:0.95rem}
    .sr-stamp.is-gap{background:none;color:#E8B39E;width:auto;padding:0 0.1rem}

    .sr-save{display:flex;justify-content:flex-end;margin-top:1.1rem}
    .sr-save .btn-primary-custom{min-height:44px;padding:0 1.35rem;font-size:0.86rem}
    .sr-save .btn-primary-custom:disabled{opacity:0.45;cursor:not-allowed;filter:none;transform:none}

    /* Reward Code Checker */
    .sr-check-row{display:flex;gap:0.6rem;align-items:stretch}
    .sr-check-row .sr-input{flex:1 1 auto;min-width:0;text-transform:uppercase;letter-spacing:0.06em}
    .sr-check-row .sr-input::placeholder{text-transform:none;letter-spacing:normal;color:#B59A90}
    .sr-check-row .btn-primary-custom{flex:0 0 auto;min-height:44px;padding:0 1.15rem}
    .sr-result{margin-top:0.95rem;border-top:1px dashed #F3DCCD;padding-top:0.95rem}
    .sr-result-box{background:#FDF0E8;border-radius:12px;padding:0.8rem 0.95rem}
    .sr-dl{display:grid;grid-template-columns:max-content minmax(0,1fr);gap:0.35rem 1rem;margin:0;font-size:0.82rem}
    .sr-dl dt{color:var(--pc-mute);font-weight:700}
    .sr-dl dd{margin:0;color:var(--pc-cocoa);font-weight:700;overflow-wrap:anywhere}

    /* Branch Overview */
    .sr-overview{display:flex;flex-direction:column}
    .sr-ov-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;column-gap:0.8rem;padding:0.8rem 0}
    .sr-ov-row + .sr-ov-row{border-top:1px dashed #F3DCCD}
    .sr-ov-row[hidden]{display:none}
    .sr-ov-name{display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;min-width:0}
    .sr-ov-name a{font-size:0.9rem;font-weight:800;color:var(--pc-cocoa);text-decoration:none;overflow-wrap:anywhere}
    .sr-ov-name a:hover{color:var(--pc-red)}
    .sr-ov-row form{margin:0}
    .sr-ov-detail{grid-column:1/-1;font-size:0.76rem;color:var(--pc-mute);margin:0.2rem 0 0;line-height:1.45}
    .sr-ov-note{font-size:0.76rem;color:#92400E;margin:0.4rem 0 0}
    .sr-pager{display:flex;align-items:center;gap:0.35rem;margin-top:auto;padding-top:0.8rem;border-top:1px dashed #F3DCCD}
    .sr-pager:empty{display:none}
    .sr-pager-info{font-size:0.74rem;color:var(--pc-mute);margin-right:auto;font-weight:600}
    .sr-pager button{min-width:30px;height:30px;border-radius:8px;border:1px solid #F3DCCD;background:#fff;font-size:0.78rem;font-weight:700;color:var(--pc-cocoa);cursor:pointer;font-family:inherit}
    .sr-pager button.is-on{background:var(--pc-peach);border-color:var(--pc-peach);color:#fff}
    .sr-pager button:disabled{opacity:0.4;cursor:default}
    .sr-pager button:focus-visible{outline:3px solid rgba(244,132,95,.5);outline-offset:1px}
</style>

@include('admin.partials.promotions-header', ['active' => 'streak-rewards'])

<div class="promo-section">
    @if($streakRewards['scope_ok'] && ! $isAdmin && $streakRewards['branch'])
        <h2 class="promo-section-title">Streak Rewards - {{ $streakRewards['branch']->name }}</h2>
    @else
        <h2 class="promo-section-title">Streak Rewards</h2>
    @endif
    <p class="promo-section-sub">Configure stamp-card rewards and review branch settings.</p>
</div>

{{-- Master Streak Calendar — owner only; admin.streak.toggle stays in the
     `role:admin` group. On when no row exists (Setting::streakCalendarEnabled()).
     Off stops NEW rewards and hides the stamp card everywhere; rewards already
     earned stay usable. --}}
@if($isAdmin)
    <section class="sr-master" data-testid="streak-switch-card">
        <span class="sr-master-icon" aria-hidden="true">📅</span>
        <div class="sr-master-text">
            <p class="sr-master-title">Master Streak Calendar</p>
            <p class="sr-master-sub">Turns the stamp card off for every branch. Already-earned rewards still work.</p>
        </div>
        <form action="{{ route('admin.streak.toggle') }}" method="POST">
            @csrf
            <span class="sr-switch-state {{ $streakRewards['master_enabled'] ? 'is-on' : '' }}" aria-hidden="true">{{ $streakRewards['master_enabled'] ? 'On' : 'Off' }}</span>
            <button type="submit" class="sr-switch {{ $streakRewards['master_enabled'] ? 'is-on' : '' }}"
                role="switch" aria-checked="{{ $streakRewards['master_enabled'] ? 'true' : 'false' }}"
                aria-label="Master Streak Calendar" data-testid="streak-master-switch"
                title="{{ $streakRewards['master_enabled'] ? 'On — click to turn off for every branch' : 'Off — click to turn on' }}"></button>
        </form>
    </section>
@endif

@if(! $streakRewards['scope_ok'])
    <div class="sr-main">
        <div class="content-card" data-testid="streak-rewards-no-branch">
            <p class="sr-warn" style="margin:0;">
                <i class="bi bi-exclamation-triangle"></i>
                Your account is not assigned to a branch, so there are no streak reward settings for you to change.
            </p>
        </div>
        <div class="sr-side">
            @include('admin.partials.streak-reward-code-check')
        </div>
    </div>
@else
    @php
        $srBranch = $streakRewards['branch'];
        $srIsDefault = $srBranch === null;
        $srBranchName = $srBranch?->name;
        $srDefaultScope = \App\Http\Controllers\Admin\StreakRewardController::DEFAULT_SCOPE;
        $srScopeValue = $srIsDefault ? $srDefaultScope : (string) $srBranch->id;
        $srProblem = \App\Services\StreakRewardSettings::problemText($streakRewards, $srBranchName);
        $srErrors = $errors->getBag(\App\Http\Controllers\Admin\StreakRewardController::ERROR_BAG_SETTINGS);
        $srTypeItem = \App\Services\StreakRewardSettings::TYPE_MENU_ITEM;
        $srTypeVoucher = \App\Services\StreakRewardSettings::TYPE_VOUCHER;
        $srType = old('reward_type', $preferredType ?? $streakRewards['type'] ?? $srTypeVoucher);
        $srVoucherId = (string) old('voucher_id', $streakRewards['voucher']['voucher_id'] ?? '');
        $srMenuItemId = (string) old('menu_item_id', $streakRewards['menu_item_id'] ?? '');
        $srDays = (int) old('days', $streakRewards['days'] > 0 ? $streakRewards['days'] : 7);

        // "All branches" + Free menu item is only possible with an item sold
        // at every branch. None can be created from Menu Items, so this is
        // the usual case: say so and offer the branch tabs instead.
        $srItemNeedsBranch = $srIsDefault && $rewardMenuItemOptions->isEmpty();
    @endphp

    <div class="sr-main">
        <section class="content-card" id="streak-rewards-card" style="scroll-margin-top:5rem;" data-testid="streak-rewards-card" data-scope="{{ $srScopeValue }}">
            <p class="sr-card-title">🔥 Streak Rewards</p>
            <p class="sr-card-sub">Set what a full stamp card gives, and turn it on or off.</p>
            <hr class="sr-divider">

            {{-- Branch tabs — owner only: All Branches, then every branch.
                 Plain links (?streak_branch=), so the "Viewing:" bar and these
                 tabs are one choice. Same tab component as the page's own
                 Vouchers / Streak Rewards / Ads Management tabs. --}}
            @if($isAdmin)
                <nav class="promo-tabs sr-branch-tabs" aria-label="Choose the branch to edit" data-testid="streak-rewards-selector">
                    <a href="{{ route('admin.streak-rewards', ['streak_branch' => $srDefaultScope]) }}#streak-rewards-card"
                       class="promo-tab sr-branch-tab {{ $srIsDefault ? 'is-active' : '' }}"
                       @if($srIsDefault) aria-current="page" @endif>
                        <i class="bi bi-globe2" aria-hidden="true"></i>
                        <span>All Branches</span>
                    </a>
                    @foreach($branchTabs as $srTab)
                        @php $srTabActive = ! $srIsDefault && $srTab->id === $srBranch->id; @endphp
                        <a href="{{ route('admin.streak-rewards', ['streak_branch' => $srTab->id]) }}#streak-rewards-card"
                           class="promo-tab sr-branch-tab {{ $srTabActive ? 'is-active' : '' }}"
                           @if($srTabActive) aria-current="page" @endif>
                            <i class="bi bi-geo-alt" aria-hidden="true"></i>
                            <span>{{ $srTab->name }}</span>
                        </a>
                    @endforeach
                </nav>
            @endif

            {{-- Where this branch's reward comes from, and the way back to
                 the global defaults when it has its own. --}}
            @unless($srIsDefault)
                <div class="sr-scope-line">
                    <span class="sr-badge {{ $streakRewards['own'] ? 'is-override' : 'is-defaults' }}" data-testid="streak-rewards-source-pill">{{ $streakRewards['own'] ? 'Branch override' : 'Uses defaults' }}</span>
                    @if($streakRewards['own'])
                        <form action="{{ route('admin.streak.rewards.default') }}" method="POST" data-testid="streak-rewards-source">
                            @csrf
                            <input type="hidden" name="branch_id" value="{{ $srBranch->id }}">
                            <button type="submit" class="sr-btn-ghost"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Use global defaults</button>
                        </form>
                    @endif
                </div>
            @endunless

            @if(! $streakRewards['master_enabled'])
                <p class="sr-warn" data-testid="streak-rewards-master-off">
                    <i class="bi bi-pause-circle"></i>
                    @if($isAdmin)
                        The Master Streak Calendar is off, so nothing is given anywhere right now.
                    @else
                        The owner turned the Streak Calendar off, so nothing is given right now.
                    @endif
                </p>
            @endif

            <form action="{{ route('admin.streak.rewards.update') }}" method="POST" autocomplete="off" data-testid="streak-rewards-form">
                @csrf
                <input type="hidden" name="branch_id" value="{{ $srScopeValue }}">

                {{-- On/Off. A branch: its own switch, posted to the toggle
                     route by this one button (formnovalidate, so an empty
                     Days field never blocks it). All Branches has no switch
                     of its own — the Master Streak Calendar is it — so the
                     row only reports that. --}}
                @unless($srIsDefault)
                    <div class="sr-setrow" data-testid="streak-branch-switch">
                        <div class="sr-setrow-text">
                            <b>On/Off</b>
                            <span>Turns the stamp card off at {{ $srBranchName }} only.</span>
                        </div>
                        <span class="sr-switch-state {{ $streakRewards['branch_enabled'] ? 'is-on' : '' }}" data-testid="streak-branch-switch-state">{{ $streakRewards['branch_enabled'] ? 'On' : 'Off' }}</span>
                        <button type="submit" formaction="{{ route('admin.streak.branch.toggle') }}" formnovalidate
                            class="sr-switch {{ $streakRewards['branch_enabled'] ? 'is-on' : '' }}"
                            role="switch" aria-checked="{{ $streakRewards['branch_enabled'] ? 'true' : 'false' }}"
                            aria-label="Stamp card at {{ $srBranchName }}"></button>
                    </div>
                @else
                    <div class="sr-setrow" data-testid="streak-default-switch">
                        <div class="sr-setrow-text">
                            <b>On/Off</b>
                            <span>Global default for every branch. It follows the Master Streak Calendar switch above.</span>
                        </div>
                        <span class="sr-switch-state {{ $streakRewards['master_enabled'] ? 'is-on' : '' }}">{{ $streakRewards['master_enabled'] ? 'On' : 'Off' }}</span>
                        <span class="sr-switch is-readonly {{ $streakRewards['master_enabled'] ? 'is-on' : '' }}"
                            role="switch" aria-checked="{{ $streakRewards['master_enabled'] ? 'true' : 'false' }}" aria-disabled="true"
                            aria-label="Global default (follows the Master Streak Calendar)"></span>
                    </div>
                @endunless

                <div class="sr-fields">
                    <div>
                        <label class="sr-label" for="srDays">Days needed <em>*</em></label>
                        <input type="number" id="srDays" name="days" class="sr-input" required
                            min="1" max="{{ \App\Services\StreakRewardSettings::MAX_DAYS }}" step="1"
                            value="{{ old('days', $streakRewards['days'] > 0 ? $streakRewards['days'] : 7) }}">
                        <span class="sr-help">How many different days with a completed order earn one reward.</span>
                        @if($srErrors->has('days'))<span class="sr-error">{{ $srErrors->first('days') }}</span>@endif
                    </div>
                    <div>
                        <label class="sr-label" for="srValidDays">Valid for (days) <em>*</em></label>
                        <input type="number" id="srValidDays" name="valid_days" class="sr-input" required
                            min="1" max="{{ \App\Services\StreakRewardSettings::MAX_VALID_DAYS }}" step="1"
                            value="{{ old('valid_days', $streakRewards['valid_days'] > 0 ? $streakRewards['valid_days'] : 30) }}">
                        <span class="sr-help">How many days a reward can be used after it's earned.</span>
                        @if($srErrors->has('valid_days'))<span class="sr-error">{{ $srErrors->first('valid_days') }}</span>@endif
                    </div>
                </div>

                @if($srProblem)
                    <p class="sr-warn" style="margin:0.9rem 0 0;" data-testid="streak-rewards-not-set">
                        <i class="bi bi-exclamation-triangle"></i> {{ $srProblem }}
                    </p>
                @endif

                <fieldset class="sr-reward">
                    <legend class="sr-label">Reward <em>*</em></legend>
                    <div class="sr-seg">
                        <label class="sr-seg-opt">
                            <input type="radio" name="reward_type" value="{{ $srTypeItem }}" data-sr-type @checked($srType === $srTypeItem)>
                            <span aria-hidden="true">🍰</span> Free menu item
                        </label>
                        <label class="sr-seg-opt">
                            <input type="radio" name="reward_type" value="{{ $srTypeVoucher }}" data-sr-type @checked($srType === $srTypeVoucher)>
                            <span aria-hidden="true">🎟</span> Voucher discount
                        </label>
                    </div>
                    @if($srErrors->has('reward_type'))<span class="sr-error">{{ $srErrors->first('reward_type') }}</span>@endif
                </fieldset>

                {{-- Free menu item --}}
                <div class="sr-field" data-sr-panel="{{ $srTypeItem }}" @if($srType !== $srTypeItem) hidden @endif>
                    @if($srItemNeedsBranch)
                        <div class="sr-perbranch" data-testid="streak-rewards-item-per-branch">
                            <p><i class="bi bi-info-circle" aria-hidden="true"></i> Free menu items are set per branch. Select a specific branch to choose the menu item.</p>
                            <div class="sr-perbranch-links">
                                @foreach($branchTabs as $srTab)
                                    <a class="sr-btn-ghost" data-testid="streak-rewards-item-branch-link"
                                       href="{{ route('admin.streak-rewards', ['streak_branch' => $srTab->id, 'reward' => $srTypeItem]) }}#streak-rewards-card">
                                        <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $srTab->name }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <label class="sr-label" for="srMenuItem">Menu item</label>
                        @if($rewardMenuItemOptions->isEmpty())
                            <p class="sr-help" style="margin-top:0;" data-testid="streak-rewards-no-items">No menu items at {{ $srBranchName }} yet. Add one in Menu Items first.</p>
                        @else
                            <select id="srMenuItem" name="menu_item_id" class="sr-input">
                                <option value="">Choose a menu item</option>
                                @foreach($rewardMenuItemOptions as $option)
                                    <option value="{{ $option->id }}" @selected($srMenuItemId === (string) $option->id)>
                                        {{ $option->name }}{{ $option->is_available ? '' : ' (switched off now)' }}
                                    </option>
                                @endforeach
                            </select>
                            <span class="sr-help">{{ $srIsDefault ? 'Only items sold at every branch can be given here.' : 'Given as one free serving, while it can still be ordered.' }}</span>
                        @endif
                    @endif
                    @if($srErrors->has('menu_item_id'))<span class="sr-error">{{ $srErrors->first('menu_item_id') }}</span>@endif
                </div>

                {{-- Voucher discount --}}
                <div class="sr-field" data-sr-panel="{{ $srTypeVoucher }}" @if($srType !== $srTypeVoucher) hidden @endif>
                    <label class="sr-label" for="srVoucher">Voucher</label>
                    @if($rewardVoucherOptions->isEmpty())
                        <p class="sr-help" style="margin-top:0;" data-testid="streak-rewards-no-vouchers">Create a voucher first in <a href="{{ route('admin.vouchers') }}" style="color:var(--pc-red);font-weight:700;">Vouchers</a>.</p>
                    @else
                        <select id="srVoucher" name="voucher_id" class="sr-input">
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
                <p class="sr-fineprint">Rewards already earned are not changed.</p>

                {{-- What a customer's card looks like with this many days:
                     a filled run, the empty days still to go, and the reward
                     at the end. Redrawn as Days needed changes. --}}
                <div class="sr-stamps" data-sr-stamps data-days="{{ $srDays }}" role="img"
                    aria-label="Stamp card preview: {{ $srDays }} {{ $srDays === 1 ? 'day' : 'days' }} to a reward"></div>

                <div class="sr-save">
                    <button type="submit" class="btn-primary-custom" data-sr-save
                        @if($srItemNeedsBranch && $srType === $srTypeItem) disabled @endif>
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Save Streak Settings
                    </button>
                </div>
            </form>
        </section>

        <div class="sr-side">
            @include('admin.partials.streak-reward-code-check')

            {{-- Branch Overview — owner only. Global defaults first, then
                 every branch: where its reward comes from, its own switch,
                 and the reward in one line. Three rows a page in the
                 browser; with no script every row simply shows. --}}
            @if($isAdmin)
                @php
                    $srDetail = function (array $current): string {
                        $days = $current['days'] > 0
                            ? $current['days'] . ' ' . ($current['days'] === 1 ? 'day' : 'days')
                            : 'No days set';
                        $reward = match (true) {
                            ! $current['configured'] => 'No reward set',
                            $current['type'] === \App\Services\StreakRewardSettings::TYPE_VOUCHER => 'Voucher ' . (($current['voucher']['code'] ?? '') !== '' ? $current['voucher']['code'] : 'discount'),
                            default => 'Free ' . ($current['menu_item']?->name ?? 'menu item'),
                        };
                        $valid = $current['valid_days'] > 0 ? 'valid ' . $current['valid_days'] . 'd' : 'no expiry';

                        return $days . ' · ' . $reward . ' · ' . $valid;
                    };
                @endphp
                <section class="content-card sr-overview" data-testid="streak-rewards-overview">
                    <p class="sr-card-title">🏪 Branch Overview</p>
                    <p class="sr-card-sub">Global defaults vs branch overrides</p>
                    <hr class="sr-divider">

                    <div data-sr-ov-list>
                        <div class="sr-ov-row" data-sr-ov-row data-global-row>
                            <div class="sr-ov-name">
                                <a href="{{ route('admin.streak-rewards', ['streak_branch' => $srDefaultScope]) }}#streak-rewards-card">🌐 Global defaults</a>
                                <span class="sr-badge {{ $streakRewards['master_enabled'] ? 'is-on' : 'is-off' }}">{{ $streakRewards['master_enabled'] ? 'ON' : 'OFF' }}</span>
                            </div>
                            <p class="sr-ov-detail">{{ $srDetail($globalDefaults) }}</p>
                        </div>
                        @foreach($streakOverview as $srRow)
                            @php $srRowBranch = $srRow['branch']; @endphp
                            <div class="sr-ov-row" data-sr-ov-row data-branch-row="{{ $srRowBranch->id }}">
                                <div class="sr-ov-name">
                                    <a href="{{ route('admin.streak-rewards', ['streak_branch' => $srRowBranch->id]) }}#streak-rewards-card">📍 {{ $srRowBranch->name }}</a>
                                    <span class="sr-badge {{ $srRow['current']['own'] ? 'is-override' : 'is-defaults' }}">{{ $srRow['current']['own'] ? 'Branch override' : 'Uses defaults' }}</span>
                                </div>
                                <form action="{{ route('admin.streak.branch.toggle') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="branch_id" value="{{ $srRowBranch->id }}">
                                    <input type="hidden" name="return_scope" value="{{ $srScopeValue }}">
                                    <button type="submit" class="sr-switch is-sm {{ $srRow['enabled'] ? 'is-on' : '' }}"
                                        role="switch" aria-checked="{{ $srRow['enabled'] ? 'true' : 'false' }}"
                                        aria-label="Stamp card at {{ $srRowBranch->name }}"
                                        title="{{ $srRow['enabled'] ? 'On — click to turn off at ' . $srRowBranch->name : 'Off — click to turn on at ' . $srRowBranch->name }}"></button>
                                </form>
                                <p class="sr-ov-detail">{{ $srDetail($srRow['current']) }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if(! $streakRewards['master_enabled'])
                        <p class="sr-ov-note"><i class="bi bi-pause-circle" aria-hidden="true"></i> The Master Streak Calendar is off, so every branch is paused.</p>
                    @endif
                    <div class="sr-pager" data-sr-pager></div>
                </section>
            @endif
        </div>
    </div>

    <script>
        (function () {
            var radios = document.querySelectorAll('[data-sr-type]');
            var panels = document.querySelectorAll('[data-sr-panel]');
            var save = document.querySelector('[data-sr-save]');
            var itemNeedsBranch = @json($srItemNeedsBranch);

            // Show the picker for the chosen reward. "All branches" + Free
            // menu item with no item sold everywhere cannot be saved, so Save
            // waits for a voucher instead (the server refuses it too).
            function syncType() {
                var picked = document.querySelector('[data-sr-type]:checked');
                panels.forEach(function (panel) {
                    panel.hidden = !picked || panel.getAttribute('data-sr-panel') !== picked.value;
                });
                if (save) {
                    var blocked = itemNeedsBranch && picked && picked.value === @json($srTypeItem);
                    save.disabled = blocked;
                    save.title = blocked ? 'Choose a branch to give a free menu item, or pick Voucher discount.' : '';
                }
            }
            radios.forEach(function (radio) { radio.addEventListener('change', syncType); });
            syncType();

            // The stamp preview follows Days needed.
            var stamps = document.querySelector('[data-sr-stamps]');
            var days = document.getElementById('srDays');
            function stamp(cls, text) {
                var s = document.createElement('span');
                s.className = 'sr-stamp' + (cls ? ' ' + cls : '');
                s.textContent = text;
                return s;
            }
            function drawStamps() {
                if (!stamps) return;
                var n = parseInt(days && days.value, 10);
                if (!(n >= 1)) n = parseInt(stamps.getAttribute('data-days'), 10) || 1;
                n = Math.min(n, {{ \App\Services\StreakRewardSettings::MAX_DAYS }});
                var filled = Math.max(0, n - 3);
                stamps.innerHTML = '';
                var shown = n <= 10 ? n - 1 : 4;
                for (var i = 1; i <= shown; i++) stamps.appendChild(stamp(i <= filled ? '' : 'is-empty', String(i)));
                if (n > 10) {
                    stamps.appendChild(stamp('is-gap', '…'));
                    stamps.appendChild(stamp('is-empty', String(n - 1)));
                }
                stamps.appendChild(stamp('is-gift', '🎁'));
                stamps.setAttribute('aria-label', 'Stamp card preview: ' + n + (n === 1 ? ' day' : ' days') + ' to a reward');
            }
            if (days) days.addEventListener('input', drawStamps);
            drawStamps();

            // Branch Overview: three rows a page.
            var list = document.querySelector('[data-sr-ov-list]');
            var pager = document.querySelector('[data-sr-pager]');
            if (list && pager) {
                var rows = Array.prototype.slice.call(list.querySelectorAll('[data-sr-ov-row]'));
                var per = 3, page = 1, pages = Math.max(1, Math.ceil(rows.length / per));
                function button(label, target, opts) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = label;
                    b.setAttribute('data-page', target);
                    if (opts.label) b.setAttribute('aria-label', opts.label);
                    if (opts.on) { b.className = 'is-on'; b.setAttribute('aria-current', 'page'); }
                    if (opts.disabled) b.disabled = true;
                    return b;
                }
                function render() {
                    rows.forEach(function (row, i) { row.hidden = i < (page - 1) * per || i >= page * per; });
                    var first = (page - 1) * per + 1, last = Math.min(page * per, rows.length);
                    pager.innerHTML = '';
                    var info = document.createElement('span');
                    info.className = 'sr-pager-info';
                    info.textContent = first + '–' + last + ' of ' + rows.length;
                    pager.appendChild(info);
                    pager.appendChild(button('‹', page - 1, { label: 'Previous page', disabled: page === 1 }));
                    for (var p = 1; p <= pages; p++) pager.appendChild(button(String(p), p, { label: 'Page ' + p, on: p === page }));
                    pager.appendChild(button('›', page + 1, { label: 'Next page', disabled: page === pages }));
                }
                pager.addEventListener('click', function (e) {
                    var b = e.target.closest ? e.target.closest('button[data-page]') : null;
                    if (!b || b.disabled) return;
                    page = Math.min(pages, Math.max(1, parseInt(b.getAttribute('data-page'), 10)));
                    render();
                });
                render();
            }

            @if($isAdmin)
            // The "Viewing:" bar at the top of the page picks the same thing
            // the tabs do, so it opens that tab.
            window.pcBranchSelectHandler = function (value) {
                var url = new URL(@json(route('admin.streak-rewards')), window.location.href);
                url.searchParams.set('streak_branch', value === 'all' ? @json($srDefaultScope) : value);
                url.hash = 'streak-rewards-card';
                window.location.assign(url.href);
                return true;
            };
            @endif
        })();
    </script>
@endif

@endsection

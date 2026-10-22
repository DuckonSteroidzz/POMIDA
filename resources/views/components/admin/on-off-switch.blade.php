{{--
    The admin portal's one ON/OFF switch (Oct 2026, Promotions & Rewards):
    a real checkbox, so it is focusable, works with Space, and reports its
    state to screen readers (role="switch"), with "ON" / "OFF" written inside
    it as in the tester's reference (docs/design-refs/peachy-promotions-rewards.html,
    .sw-wheel). Used by the Vouchers tab's Wheel column and Spin Wheel card;
    the Ads tab is meant to reuse it.

        <x-admin.on-off-switch :checked="$on" :disabled="! $canChange"
            label="Spin wheel for CODE" title="Why it is disabled, or what it does"
            data-anything="..." onchange="..." />

    Every other attribute lands on the <input>. The switch only SHOWS a state
    and fires `change`; what a change does (a fetch, a form submit) belongs to
    the page, and whether it is allowed belongs to the server. `title` goes on
    the label and the input, so the tooltip shows on a disabled switch too.

    The style ships with the first switch a render draws (@once), so a page,
    and a background-refreshed fragment of a page, always carries it.
--}}
@props(['checked' => false, 'disabled' => false, 'label' => null, 'title' => null])
@once
<style>
    .pc-switch { position: relative; display: inline-block; flex: 0 0 auto; width: 58px; height: 25px; vertical-align: middle; }
    .pc-switch-input { position: absolute; inset: 0; z-index: 1; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
    .pc-switch-track { position: absolute; inset: 0; border-radius: 999px; background: #E5D6CF; transition: background 0.2s ease; pointer-events: none; }
    .pc-switch-knob { position: absolute; top: 4px; left: 4px; width: 17px; height: 17px; border-radius: 50%; background: #fff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15); transition: transform 0.2s ease; }
    .pc-switch-text { position: absolute; top: 50%; transform: translateY(-50%); font-family: 'Poppins', sans-serif; font-size: 9px; font-weight: 800; line-height: 1; letter-spacing: 0.02em; }
    .pc-switch-text.is-off { right: 7px; color: #75645E; }
    .pc-switch-text.is-on { left: 8px; color: #fff; display: none; }
    .pc-switch-input:checked + .pc-switch-track { background: #F4845F; }
    .pc-switch-input:checked + .pc-switch-track .pc-switch-knob { transform: translateX(33px); }
    .pc-switch-input:checked + .pc-switch-track .is-on { display: block; }
    .pc-switch-input:checked + .pc-switch-track .is-off { display: none; }
    .pc-switch-input:focus-visible + .pc-switch-track { outline: 3px solid rgba(244, 132, 95, 0.45); outline-offset: 2px; }
    .pc-switch-input:disabled { cursor: not-allowed; }
    .pc-switch-input:disabled + .pc-switch-track { opacity: 0.5; }
    .pc-switch.is-busy .pc-switch-input { cursor: progress; }
    .pc-switch.is-busy .pc-switch-track { opacity: 0.7; }
</style>
@endonce
<label class="pc-switch" @if($title) title="{{ $title }}" @endif>
    <input type="checkbox" role="switch" class="pc-switch-input" {{ $attributes }} @if($label) aria-label="{{ $label }}" @endif @if($title) title="{{ $title }}" @endif @checked($checked) @disabled($disabled)>
    <span class="pc-switch-track" aria-hidden="true"><span class="pc-switch-text is-on">ON</span><span class="pc-switch-text is-off">OFF</span><span class="pc-switch-knob"></span></span>
</label>

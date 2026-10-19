{{--
    "Promotions & Rewards" — the one admin page that holds Vouchers, Streak
    Rewards and Ads Management (Oct 2026). Each tab is still its own screen at
    its own URL (/admin/vouchers, /admin/streak-rewards, /admin/ads) with its own
    controller, forms, validation and role gate, so nothing a form does changed
    and a redirect after saving lands back on the same tab. This partial is the
    shared top of all three: the title, the subtitle and the pill tab bar.

    $active — 'vouchers' | 'streak-rewards' | 'ads', the tab being shown.

    A tab is listed only when the viewer can reach it today. The matrix is the
    route tiers in routes/web.php: Vouchers is open to every portal role (staff
    read it and issue codes; the screen itself hides what they may not do),
    Streak Rewards and Ads are the manager tier (owner + supervisor, a supervisor
    on their own branch only), so isManager() is the same membership the
    `role:admin,supervisor` group is spelled from. Presentation only: the
    routes are the boundary, a hidden tab is refused server-side too.
--}}
@php
    $promoViewer = \Illuminate\Support\Facades\Auth::guard('admin')->user();
    $promoIsManager = $promoViewer?->isManager() ?? false;

    $promoTabs = array_values(array_filter([
        ['key' => 'vouchers', 'label' => 'Vouchers', 'icon' => 'bi-ticket-perforated', 'route' => 'admin.vouchers', 'show' => true],
        ['key' => 'streak-rewards', 'label' => 'Streak Rewards', 'icon' => 'bi-gift', 'route' => 'admin.streak-rewards', 'show' => $promoIsManager],
        ['key' => 'ads', 'label' => 'Ads Management', 'icon' => 'bi-megaphone', 'route' => 'admin.ads', 'show' => $promoIsManager],
    ], fn ($tab) => $tab['show']));
@endphp

<style>
    .promo-head { margin: 0 0 1.1rem; }
    .promo-head .page-title { margin-bottom: 0.15rem; }
    .promo-sub { font-size: 0.85rem; color: #8A6A61; margin: 0 0 0.9rem; }

    /* Pill tab bar. Scrolls sideways inside itself on a phone — the page
       never does (min-width:0 + overflow-x on the bar). */
    .promo-tabs {
        display: flex; gap: 0.35rem; padding: 0.3rem;
        background: #fff; border: 1px solid #F0E2D5; border-radius: 999px;
        max-width: 100%; width: max-content; min-width: 0;
        overflow-x: auto; scrollbar-width: none; -webkit-overflow-scrolling: touch;
        box-shadow: 0 1px 2px rgba(139, 26, 26, 0.04);
    }
    .promo-tabs::-webkit-scrollbar { display: none; }
    .promo-tab {
        display: inline-flex; align-items: center; gap: 0.45rem; flex: 0 0 auto;
        min-height: 40px; padding: 0 1.05rem; border-radius: 999px;
        font-family: 'Poppins', sans-serif; font-size: 0.82rem; font-weight: 600;
        color: #7B645C; text-decoration: none; white-space: nowrap;
        transition: background 0.15s ease, color 0.15s ease;
    }
    .promo-tab:hover { background: #FFF4EC; color: #C0392B; }
    .promo-tab:focus-visible { outline: 3px solid rgba(244, 132, 95, 0.5); outline-offset: 2px; }
    .promo-tab.is-active {
        background: linear-gradient(135deg, #F4845F, #E86B5A); color: #fff;
        box-shadow: 0 4px 10px -4px rgba(192, 57, 43, 0.55);
    }
    .promo-section { margin: 0 0 0.9rem; }
    .promo-section-title { font-family: 'Fraunces', Georgia, serif; font-size: 1.15rem; font-weight: 700; color: #8B1A1A; margin: 0; }
    .promo-section-sub { font-size: 0.82rem; color: #8A6A61; margin: 0.1rem 0 0; }
    @media (max-width: 540px) {
        .promo-tabs { width: 100%; }
        .promo-tab { padding: 0 0.85rem; }
    }
</style>

<div class="promo-head">
    <p class="page-title">Promotions &amp; Rewards</p>
    <p class="promo-sub">Vouchers, streak rewards and advertisements in one place.</p>

    <nav class="promo-tabs" aria-label="Promotions &amp; Rewards sections">
        @foreach($promoTabs as $tab)
            <a href="{{ route($tab['route']) }}"
               class="promo-tab {{ $active === $tab['key'] ? 'is-active' : '' }}"
               @if($active === $tab['key']) aria-current="page" @endif>
                <i class="bi {{ $tab['icon'] }}" aria-hidden="true"></i>
                <span>{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </nav>
</div>

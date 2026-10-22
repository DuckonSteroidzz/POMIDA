{{--
    The Ads Management tab's results area: the table (or the empty state). It
    is the one part of admin.ads that changes when the search box, a filter or
    the "Viewing:" bar does.

    Rendered two ways, from the same data and the same controller action
    (AdminController::showAds):

      1. Included in the full page, so /admin/ads?status=live shows the
         filtered list with no JavaScript involved.
      2. Returned ALONE when the page's script asks for it in the background
         (header X-Ads-Fragment: 1), so a filter change swaps this region
         instead of reloading the document.

    $ads     — the already viewer-scoped, Viewing-branch-narrowed, filtered ads.
    $filters — q / placement / status, cleaned by adListFilters().
    $adStats — the four stat-card counts for the viewer's Viewing branch.

    Each ad is drawn twice: a table row (wider screens) and a card (a phone,
    max-width 640px), as in the tester's reference. Only one is displayed; both
    carry the same Active switch and the same ⋮ menu (admin/partials/ad-row-menu),
    and the page's script keeps the two copies of one ad in step.

    Presentation only. $canManageAd mirrors promotionScopeRefusal() — the
    switch and menu agreeing with the server, never a substitute for it.
--}}
@php
    $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

    $canManageAd = function ($ad) use ($lockedBranchId) {
        // Owner — every ad, global ones included.
        if ($lockedBranchId === null) {
            return true;
        }

        // Supervisor — own branch only, never a global ad. The NULL arm is
        // spelled out for the reason promotionScopeRefusal() gives.
        return $ad->branch_id !== null && (int) $ad->branch_id === $lockedBranchId;
    };

    $filtersOn = $filters['q'] !== '' || $filters['placement'] !== '' || $filters['status'] !== '';
    $statusLabels = ['live' => 'Live', 'inactive' => 'Inactive', 'expired' => 'Expired', 'scheduled' => 'Scheduled'];
    $placementLabels = ['game' => ['🎮', 'Game Page'], 'menu' => ['📋', 'Menu Page']];

    // Everything one ad's row and card both print, worked out once.
    $present = function ($ad) use ($canManageAd, $statusLabels) {
        $canManage = $canManageAd($ad);
        $status = $ad->listStatus();

        return [
            'canManage'   => $canManage,
            'status'      => $status,
            'statusLabel' => $statusLabels[$status],
            'image'       => $ad->image ? \App\Support\Img::url($ad->image) : null,
            'start'       => $ad->starts_at ? $ad->starts_at->format('M d, Y') : '—',
            // The last shop day it shows on, read by the rule Ad::hasEnded() uses.
            'end'         => $ad->ends_at ? \App\Support\ShopDay::formatExpiry($ad->ends_at) : '—',
            'switchTitle' => $canManage
                ? 'On: shown to customers during its dates · Off: hidden'
                : 'Company-wide ads can only be changed by the owner.',
        ];
    };
@endphp
<div id="adsResults" data-testid="ad-results" data-total="{{ count($ads) }}" data-filters-on="{{ $filtersOn ? '1' : '0' }}"
    data-stat-total="{{ $adStats['total'] }}" data-stat-live="{{ $adStats['live'] }}" data-stat-game="{{ $adStats['game'] }}" data-stat-menu="{{ $adStats['menu'] }}">
    @if(count($ads) > 0)
    @php $shown = $ads->mapWithKeys(fn ($ad) => [$ad->id => $present($ad)]); @endphp
    <div class="ads-table-wrap">
        <table class="ads-table">
            <thead>
                <tr>
                    <th>Ad</th>
                    <th>Placement</th>
                    <th>Schedule</th>
                    <th>Scope</th>
                    <th>Status</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ads as $ad)
                @php $p = $shown[$ad->id]; @endphp
                <tr data-ad-row="{{ $ad->id }}" data-status="{{ $p['status'] }}">
                    <td class="ads-cell-ad">
                        <div class="ads-ad">
                            @if($p['image'])
                            <img class="ads-thumb" src="{{ $p['image'] }}" alt="" loading="lazy">
                            @else
                            <span class="ads-thumb is-empty" aria-hidden="true">🖼️</span>
                            @endif
                            <div class="ads-ad-text">
                                <span class="ads-title">{{ $ad->title }}</span>
                                @if($ad->description)
                                <span class="ads-desc">{{ $ad->description }}</span>
                                @endif
                                @if($ad->link)
                                <a class="ads-link" href="{{ $ad->link }}" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">🔗</span> {{ $ad->link }}</a>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="ads-cell-place">@include('admin.partials.ad-placement', ['ad' => $ad, 'placementLabels' => $placementLabels])</td>
                    <td class="ads-cell-schedule">
                        <span>{{ $p['start'] }}</span>
                        <span class="ads-muted">to {{ $p['end'] }}</span>
                    </td>
                    <td>@include('admin.partials.ad-scope-badge', ['ad' => $ad])</td>
                    <td class="ads-cell-status">
                        <span class="ads-status is-{{ $p['status'] }}" data-ad-status="{{ $ad->id }}">{{ $p['statusLabel'] }}</span>
                    </td>
                    {{-- Active: the ad's on/off (is_active), sent by the page's
                         script to the unchanged admin.ads.toggle route. --}}
                    <td class="ads-cell-active">
                        <x-admin.on-off-switch :checked="$ad->is_active" :disabled="! $p['canManage']"
                            :title="$p['switchTitle']" label="Active: {{ $ad->title }}"
                            data-ad-active="{{ $ad->id }}"
                            :data-url="$p['canManage'] ? route('admin.ads.toggle', $ad->id) : null" />
                    </td>
                    <td class="ads-cell-actions">
                        <button type="button" class="ads-kebab" data-ads-menu-btn aria-haspopup="menu" aria-expanded="false"
                            aria-controls="adsMenu{{ $ad->id }}" aria-label="Actions for {{ $ad->title }}" title="Actions">
                            <span aria-hidden="true">⋮</span>
                        </button>
                        @include('admin.partials.ad-row-menu', ['menuId' => 'adsMenu' . $ad->id, 'canManage' => $p['canManage']])
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- A phone: one card per ad instead of a sideways-scrolling table. --}}
    <div class="ads-cards" data-testid="ad-cards">
        @foreach($ads as $ad)
        @php $p = $shown[$ad->id]; @endphp
        <article class="ads-mc" data-ad-card="{{ $ad->id }}">
            <div class="ads-mc-top">
                <div class="ads-ad">
                    @if($p['image'])
                    <img class="ads-thumb" src="{{ $p['image'] }}" alt="" loading="lazy">
                    @else
                    <span class="ads-thumb is-empty" aria-hidden="true">🖼️</span>
                    @endif
                    <div class="ads-ad-text">
                        <span class="ads-title">{{ $ad->title }}</span>
                        <span class="ads-muted">@include('admin.partials.ad-placement', ['ad' => $ad, 'placementLabels' => $placementLabels])</span>
                    </div>
                </div>
                <span class="ads-status is-{{ $p['status'] }}" data-ad-status="{{ $ad->id }}">{{ $p['statusLabel'] }}</span>
            </div>
            @if($ad->description || $ad->link)
            <div class="ads-mc-text">
                @if($ad->description)
                <span class="ads-desc">{{ $ad->description }}</span>
                @endif
                @if($ad->link)
                <a class="ads-link" href="{{ $ad->link }}" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">🔗</span> {{ $ad->link }}</a>
                @endif
            </div>
            @endif
            <dl class="ads-mc-dl">
                <div><dt>Start</dt><dd>{{ $p['start'] }}</dd></div>
                <div><dt>End</dt><dd>{{ $p['end'] }}</dd></div>
                <div><dt>Scope</dt><dd>@include('admin.partials.ad-scope-badge', ['ad' => $ad])</dd></div>
                <div><dt>Active</dt><dd>
                    <x-admin.on-off-switch :checked="$ad->is_active" :disabled="! $p['canManage']"
                        :title="$p['switchTitle']" label="Active: {{ $ad->title }}"
                        data-ad-active="{{ $ad->id }}"
                        :data-url="$p['canManage'] ? route('admin.ads.toggle', $ad->id) : null" />
                </dd></div>
            </dl>
            <div class="ads-mc-actions">
                <button type="button" class="ads-kebab" data-ads-menu-btn aria-haspopup="menu" aria-expanded="false"
                    aria-controls="adsMenuM{{ $ad->id }}" aria-label="Actions for {{ $ad->title }}" title="Actions">
                    <span aria-hidden="true">⋮</span>
                </button>
                @include('admin.partials.ad-row-menu', ['menuId' => 'adsMenuM' . $ad->id, 'canManage' => $p['canManage']])
            </div>
        </article>
        @endforeach
    </div>
    @elseif($filtersOn)
    <div class="ads-empty">
        <i class="bi bi-search"></i>
        No advertisements match these filters. <a href="{{ route('admin.ads') }}" class="ads-clear-filters" data-ads-clear>Clear filters</a>
    </div>
    @else
    <div class="ads-empty">
        <i class="bi bi-megaphone"></i>
        No advertisements yet. Create one above!
    </div>
    @endif
</div>

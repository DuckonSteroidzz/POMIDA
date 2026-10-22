@extends('admin.layout')

@section('title', 'Ads - Peachy Admin')

@section('content')

@php
    /*
     * "Manage Advertisements" is Y | LIMITED | N. The role:admin,supervisor
     * group already keeps staff off this page entirely, so everyone who gets
     * here may manage SOMETHING — the question is WHICH ads.
     *
     * ads.branch_id is nullable and NULL means GLOBAL, exactly as it does on
     * vouchers and menu items. showAds() has already narrowed a supervisor's
     * listing to their own branch plus the globals (and the owner's to the
     * "Viewing:" branch); the globals are rendered read-only for a supervisor
     * (disabled switch, "Read-only" menu) so a branch manager can see the
     * company-wide campaigns running alongside theirs without being able to
     * change one. See admin/partials/ad-list-results, whose $canManageAd
     * mirrors AdminController::promotionScopeRefusal() — that method is the
     * security boundary; the page only keeps the controls honest about it.
     *
     * Layout follows the tester's reference (Defect Report 3 part B,
     * docs/design-refs/peachy-promotions-rewards.html, Ads Management tab).
     */
    $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();
    $ownBranchName = $lockedBranchId !== null
        ? (\Illuminate\Support\Facades\Auth::guard('admin')->user()?->branch?->name ?? 'Your branch')
        : null;
    $filtersOn = $filters['q'] !== '' || $filters['placement'] !== '' || $filters['status'] !== '';
@endphp

<style>
    .pchy-modal-overlay{display:none;position:fixed;inset:0;background:rgba(74,59,54,.5);z-index:1000;align-items:center;justify-content:center;padding:1rem}
    .pchy-modal-overlay.show{display:flex}
    .pchy-modal-box{background:#fff;border-radius:18px;padding:1.3rem;width:100%;max-width:620px;max-height:calc(100vh - 2rem);overflow-y:auto;box-shadow:0 20px 50px -20px rgba(139,26,26,.5)}
    .pchy-modal-hd{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1rem;padding-bottom:.85rem;border-bottom:2px dashed rgba(244,132,95,.3)}
    .pchy-modal-title{font-family:'Poppins',sans-serif;font-size:1.05rem;font-weight:700;color:#8B1A1A;margin:0;display:flex;align-items:center;gap:.5rem;}
    .pchy-modal-close{border:0;background:none;color:#9a837b;font-size:1.5rem;line-height:1;cursor:pointer;padding:.2rem .4rem}
    .pchy-modal-close:hover{color:#C0392B}
    .pchy-modal-preview-wrap{display:flex;flex-direction:column;align-items:center;gap:.4rem;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px dashed rgba(244,132,95,.3)}
    .pchy-modal-preview{width:200px;max-width:100%;height:120px;object-fit:cover;border-radius:12px;border:1px solid #F6B49B;display:block}
    .pchy-modal-preview-ph{width:200px;max-width:100%;height:120px;border-radius:12px;border:1px solid #F6B49B;background:linear-gradient(135deg,#FDF1E6,#F8D7B0);color:#F4845F;display:grid;place-items:center;font-size:1.6rem}
    .pchy-modal-preview-cap{font-size:.74rem;color:#9A837B}
    .pchy-modal-foot{display:flex;justify-content:flex-end;gap:.6rem;margin-top:1rem;flex-wrap:wrap}
    @media (max-width:640px){.pchy-modal-foot > *{flex:1}}

    /* ── Promotions & Rewards > Ads Management (Defect Report 3 part B;
         docs/design-refs/peachy-promotions-rewards.html). Same visual language
         as the Vouchers tab, with its own names so neither tab can restyle
         the other. ── */
    .ads-section-head { margin: 0 0 1rem; padding: 0.2rem 0 0.8rem; border-bottom: 2px dashed rgba(244, 132, 95, 0.28); }
    .ads-section-title { font-size: 1.05rem; font-weight: 700; color: #8B1A1A; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .ads-section-sub { font-size: 0.8rem; color: #9A837B; margin: 0.1rem 0 0; }

    .ads-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.2rem; }
    @media (max-width: 1100px) { .ads-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .ads-stat { min-width: 0; background: #fff; border: 1px solid rgba(244, 132, 95, 0.28); border-radius: 16px; padding: 1rem; box-shadow: 0 10px 30px -18px rgba(139, 26, 26, 0.45); }
    .ads-stat-icon { float: right; font-size: 1.3rem; line-height: 1; }
    .ads-stat-num { font-size: 1.5rem; font-weight: 700; line-height: 1.2; color: #8B1A1A; margin: 0; }
    .ads-stat-label { font-size: 0.78rem; color: #9A837B; margin: 0; }
    @media (max-width: 640px) { .ads-stat-num { font-size: 1.25rem; } }

    .ads-card { background: #fff; border: 1px solid rgba(244, 132, 95, 0.28); border-radius: 16px; padding: 1.25rem; margin-bottom: 1.2rem; min-width: 0; box-shadow: 0 10px 30px -18px rgba(139, 26, 26, 0.45); }
    @media (max-width: 640px) { .ads-card { padding: 1rem; } }
    .ads-card-head { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; margin-bottom: 1rem; padding-bottom: 0.8rem; border-bottom: 2px dashed rgba(244, 132, 95, 0.28); }
    .ads-card-title { font-size: 1.05rem; font-weight: 700; color: #8B1A1A; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .ads-card-sub { font-size: 0.8rem; color: #9A837B; margin: 0.1rem 0 0; }
    .ads-count { display: inline-block; background: #FDE8DE; color: #C0392B; border-radius: 999px; padding: 0.05rem 0.55rem; font-size: 0.72rem; font-weight: 700; }

    .ads-errors { margin: 0 0 1.2rem; padding: 0.7rem 0.9rem; border-radius: 12px; background: #FDECEA; border: 1px solid #F2B8B0; color: #8B1A1A; font-size: 0.82rem; }
    .ads-errors p { margin: 0 0 0.25rem; font-weight: 700; }
    .ads-errors ul { margin: 0; padding-left: 1.1rem; }

    /* The create form: four columns on a desktop, two on a tablet, one on a phone. */
    .ads-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.9rem 1rem; align-items: start; }
    .ads-grid > div { min-width: 0; }
    .ads-grid .ads-wide { grid-column: span 2; }
    .ads-grid .ads-full { grid-column: 1 / -1; }
    .ads-grid.is-two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (max-width: 1100px) { .ads-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .ads-grid, .ads-grid.is-two { grid-template-columns: minmax(0, 1fr); } .ads-grid .ads-wide, .ads-grid .ads-full { grid-column: auto; } }
    /* The grid gap spaces the fields; the portal-wide bottom margin would push
       each hint away from its own field. */
    .ads-grid .form-control-custom { margin-bottom: 0; }
    .ads-grid textarea.form-control-custom { resize: vertical; min-height: 70px; }
    .ads-grid select:disabled { color: #9A837B; cursor: not-allowed; }
    .ads-hint { display: block; font-size: 0.72rem; color: #9A837B; margin-top: 0.25rem; }
    .ads-actions { display: flex; justify-content: flex-end; align-items: center; gap: 0.6rem; margin-top: 1rem; flex-wrap: wrap; }
    @media (max-width: 640px) { .ads-actions > * { flex: 1; } }
    .ads-btn-ghost { background: #fff; color: #C0392B; border: 1.5px solid #F6B49B; border-radius: 10px; padding: 0.6rem 1.1rem; min-height: 42px; font-size: 0.85rem; font-weight: 700; cursor: pointer; font-family: 'Poppins', sans-serif; }
    .ads-btn-ghost:hover { background: #FFF4EC; }
    .ads-actions .btn-primary-custom, .pchy-modal-foot .btn-primary-custom { min-height: 42px; }

    /* "Tap to upload an image": the whole box is the file picker's label. The
       input stays in the page (visually hidden, not display:none) so it can be
       reached with Tab and opened with Enter/Space. */
    .ads-drop { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.5rem; min-height: 96px; margin: 0; padding: 1rem; border: 2px dashed #F6B49B; border-radius: 12px; background: #FFF4EC; color: #9A837B; font-size: 0.85rem; text-align: center; cursor: pointer; transition: border-color 0.15s ease, background 0.15s ease; }
    .ads-drop:hover, .ads-drop.is-over { border-color: #F4845F; background: #FDF1E6; }
    .ads-drop:focus-within { border-color: #F4845F; box-shadow: 0 0 0 3px rgba(244, 132, 95, 0.18); }
    .ads-drop-input { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0; opacity: 0; }
    .ads-drop-text { font-weight: 600; color: #8B1A1A; }
    .ads-drop img { display: block; max-width: 100%; max-height: 140px; border-radius: 10px; }
    .ads-drop img[hidden] { display: none; }
    .ads-drop-hint { font-size: 0.72rem; color: #9A837B; }

    /* Placement: two radio cards. */
    .ads-place-pick { display: flex; flex-wrap: wrap; gap: 0.6rem; margin: 0; padding: 0; border: 0; }
    .ads-place-pick legend { padding: 0; }
    .ads-place-opt { flex: 1 1 130px; min-width: 0; display: flex; align-items: center; gap: 0.5rem; min-height: 46px; margin: 0; padding: 0.7rem; border: 1.5px solid #F6B49B; border-radius: 12px; background: #fff; color: #4A3B36; font-size: 0.85rem; font-weight: 600; cursor: pointer; }
    .ads-place-opt input { margin: 0; accent-color: #C0392B; }
    .ads-place-opt:has(input:checked) { background: #FFF4EC; border-color: #F4845F; }
    .ads-place-opt:has(input:focus-visible) { box-shadow: 0 0 0 3px rgba(244, 132, 95, 0.25); }

    .ads-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; margin-bottom: 0.9rem; }
    .ads-search { display: flex; align-items: center; gap: 0.5rem; flex: 1 1 160px; min-width: 160px; margin: 0; padding: 0 0.8rem; min-height: 42px; background: #fff; border: 1.5px solid #F6B49B; border-radius: 10px; color: #9A837B; }
    .ads-search:focus-within { border-color: #F4845F; box-shadow: 0 0 0 3px rgba(244, 132, 95, 0.18); }
    .ads-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-size: 0.85rem; color: #333; font-family: inherit; }
    .ads-filter { flex: 0 0 auto; min-width: 150px; width: auto; margin: 0; }
    .ads-clear-filters { font-size: 0.8rem; font-weight: 600; color: #C0392B; text-decoration: none; }
    .ads-clear-filters:hover { text-decoration: underline; }
    .ads-clear-filters[hidden] { display: none; }
    @media (max-width: 640px) { .ads-search, .ads-filter { flex: 1 1 100%; } }
    /* Background refresh: the old list stays, dimmed, until the new one is in. */
    #adsResults { transition: opacity 0.15s ease; }
    #adsResults.is-loading { opacity: 0.5; pointer-events: none; }
    .ads-error { margin: 0 0 0.8rem; padding: 0.55rem 0.8rem; border-radius: 10px; background: #FDE2DC; color: #8B1A1A; font-size: 0.8rem; font-weight: 600; }
    .ads-error[hidden] { display: none; }
    .ads-error-retry { margin-left: 0.4rem; border: 0; background: none; padding: 0; color: #C0392B; font: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }

    /* The table scrolls inside its own wrapper; the page never does. */
    .ads-table-wrap { overflow-x: auto; max-width: 100%; border: 1px solid rgba(244, 132, 95, 0.28); border-radius: 12px; }
    .ads-table { width: 100%; min-width: 820px; border-collapse: collapse; font-size: 0.82rem; }
    .ads-table th { background: #FDF1E6; color: #8B1A1A; font-size: 0.74rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; text-align: left; padding: 0.7rem 0.8rem; white-space: nowrap; }
    .ads-table td { padding: 0.75rem 0.8rem; border-top: 1px solid rgba(244, 132, 95, 0.28); vertical-align: middle; color: #4A3B36; }
    .ads-table tbody tr:hover td { background: #FFFAF6; }
    .ads-cell-ad { min-width: 260px; max-width: 360px; }
    .ads-ad { display: flex; align-items: center; gap: 0.7rem; min-width: 0; }
    .ads-ad-text { min-width: 0; display: flex; flex-direction: column; gap: 0.1rem; }
    .ads-thumb { flex: 0 0 auto; width: 84px; height: 52px; border-radius: 9px; object-fit: cover; border: 1px solid #F6B49B; background: linear-gradient(135deg, #FDF1E6, #F8D7B0); }
    .ads-thumb.is-empty { display: grid; place-items: center; color: #F4845F; font-size: 1.2rem; }
    .ads-title { font-weight: 700; color: #8B1A1A; overflow-wrap: anywhere; }
    .ads-desc { font-size: 0.74rem; color: #9A837B; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
    .ads-link { font-size: 0.74rem; color: #C0392B; text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; }
    .ads-link:hover { text-decoration: underline; }
    .ads-muted { display: block; font-size: 0.74rem; color: #9A837B; }
    .ads-cell-place, .ads-cell-schedule { white-space: nowrap; }
    .ads-place { white-space: nowrap; }
    .ads-place.is-removed { display: inline-block; background: #9CA3AF; color: #fff; padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; }
    .ads-place-note { display: block; margin-top: 0.2rem; font-size: 0.68rem; font-weight: 600; color: #C0392B; white-space: normal; }
    .ads-badge, .ads-status { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.18rem 0.55rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; white-space: nowrap; }
    .ads-badge.is-global { background: #F8D7B0; color: #8B1A1A; }
    .ads-badge.is-branch { background: #FFF4EC; color: #C0392B; border: 1px solid #F6B49B; }
    .ads-status.is-live { background: #E6F5EC; color: #2E8B57; }
    .ads-status.is-live::before { content: "●"; }
    .ads-status.is-inactive { background: #F1ECEA; color: #8A7A75; }
    .ads-status.is-inactive::before { content: "○"; }
    .ads-status.is-expired { background: #FDECEA; color: #C0392B; }
    .ads-status.is-expired::before { content: "⏰"; }
    .ads-status.is-scheduled { background: #FDF3DC; color: #B7791F; }
    .ads-status.is-scheduled::before { content: "🗓"; }
    .ads-cell-status, .ads-cell-active { white-space: nowrap; }

    /* The ⋮ button and its menu. The menu is display:none when closed, so it
       can never sit over another row's controls. */
    .ads-kebab { display: inline-grid; place-items: center; width: 36px; height: 34px; padding: 0; border: 1px solid #F6B49B; border-radius: 9px; background: #fff; color: #8B1A1A; font-size: 1.25rem; font-weight: 800; line-height: 1; cursor: pointer; transition: background 0.15s ease, border-color 0.15s ease; }
    .ads-kebab:hover, .ads-kebab[aria-expanded="true"] { background: #FFF4EC; border-color: #F4845F; }
    .ads-kebab:focus-visible { outline: 3px solid rgba(244, 132, 95, 0.25); outline-offset: 2px; }
    .ads-menu { position: fixed; z-index: 1100; display: grid; gap: 2px; min-width: 140px; padding: 5px; text-align: left; background: #fff; border: 1px solid rgba(244, 132, 95, 0.28); border-radius: 10px; box-shadow: 0 10px 24px rgba(74, 59, 54, 0.16); }
    .ads-menu[hidden] { display: none; }
    .ads-menu form { margin: 0; }
    .ads-menu-item { display: flex; align-items: center; gap: 0.5rem; width: 100%; min-height: 34px; padding: 0.55rem 0.65rem; border: 0; border-radius: 8px; background: transparent; color: #4A3B36; font-family: 'Poppins', sans-serif; font-size: 0.8rem; font-weight: 600; text-align: left; white-space: nowrap; cursor: pointer; }
    .ads-menu-item:hover, .ads-menu-item:focus-visible { background: #FFF4EC; color: #8B1A1A; outline: none; }
    .ads-menu-item.is-danger { color: #C0392B; }
    .ads-menu-ico { display: inline-block; width: 1.2em; text-align: center; }
    .ads-menu-lock { padding: 0.55rem 0.7rem; color: #9A837B; font-size: 0.75rem; white-space: nowrap; }

    /* A phone: cards instead of the table, as in the reference. */
    .ads-cards { display: none; }
    @media (max-width: 640px) {
        .ads-table-wrap { display: none; }
        .ads-cards { display: block; }
    }
    .ads-mc { border: 1px solid rgba(244, 132, 95, 0.28); border-radius: 14px; padding: 0.9rem; margin-bottom: 0.7rem; background: #fff; }
    .ads-mc-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.6rem; }
    .ads-mc-top > .ads-ad { flex: 1 1 auto; }
    .ads-mc-text { display: flex; flex-direction: column; gap: 0.15rem; margin-top: 0.6rem; min-width: 0; }
    .ads-mc-dl { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 0.4rem 0.8rem; margin: 0.7rem 0; font-size: 0.8rem; }
    .ads-mc-dl dt { color: #9A837B; font-size: 0.7rem; font-weight: 400; }
    .ads-mc-dl dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; color: #4A3B36; }
    .ads-mc-actions { display: flex; align-items: center; gap: 0.35rem; }

    .ads-empty { text-align: center; padding: 2rem 1rem; color: #9A837B; font-size: 0.85rem; }
    .ads-empty i { font-size: 2.5rem; display: block; margin-bottom: 0.5rem; color: #E5D6CF; }

    /* Messages from the Active switch: what changed, or why the server said no. */
    .ads-toast { position: fixed; left: 50%; bottom: 1.2rem; z-index: 1200; max-width: calc(100vw - 2rem); padding: 0.7rem 1.1rem; border-radius: 12px; background: #8B1A1A; color: #fff; font-size: 0.85rem; font-weight: 600; text-align: center; box-shadow: 0 12px 28px rgba(74, 59, 54, 0.25); transform: translateX(-50%) translateY(12px); opacity: 0; visibility: hidden; pointer-events: none; transition: opacity 0.25s, transform 0.25s, visibility 0.25s; }
    .ads-toast.is-shown { transform: translateX(-50%) translateY(0); opacity: 1; visibility: visible; }
    .ads-toast.is-error { background: #C0392B; }
</style>

@include('admin.partials.promotions-header', ['active' => 'ads'])

<div class="ads-section-head">
    <h2 class="ads-section-title"><span aria-hidden="true">📣</span> Ads Management</h2>
    <p class="ads-section-sub">Create and manage advertisements shown to customers.</p>
</div>

{{-- A refused create or edit used to come back with nothing on screen. --}}
@if($errors->any())
<div class="ads-errors" role="alert" data-testid="ad-errors">
    <p>The ad was not saved:</p>
    <ul>
        @foreach($errors->all() as $message)
        <li>{{ $message }}</li>
        @endforeach
    </ul>
</div>
@endif

{{-- Four counts over every ad this viewer may see in the branch they are
     viewing, whatever the list's filters say. "Live now" is the rule the
     Game and Menu pages use (Ad::scopeLiveNow). --}}
<div class="ads-stats" data-testid="ad-stats">
    <div class="ads-stat">
        <span class="ads-stat-icon" aria-hidden="true">📣</span>
        <p class="ads-stat-num" data-ad-stat="total">{{ number_format($adStats['total']) }}</p><p class="ads-stat-label">Total ads</p>
    </div>
    <div class="ads-stat">
        <span class="ads-stat-icon" aria-hidden="true">✅</span>
        <p class="ads-stat-num" data-ad-stat="live">{{ number_format($adStats['live']) }}</p><p class="ads-stat-label">Live now</p>
    </div>
    <div class="ads-stat">
        <span class="ads-stat-icon" aria-hidden="true">🎮</span>
        <p class="ads-stat-num" data-ad-stat="game">{{ number_format($adStats['game']) }}</p><p class="ads-stat-label">Game Page</p>
    </div>
    <div class="ads-stat">
        <span class="ads-stat-icon" aria-hidden="true">📋</span>
        <p class="ads-stat-num" data-ad-stat="menu">{{ number_format($adStats['menu']) }}</p><p class="ads-stat-label">Menu Page</p>
    </div>
</div>

<div class="ads-card" data-testid="create-ad-card">
    <div class="ads-card-head">
        <div>
            <p class="ads-card-title"><span aria-hidden="true">📣</span> Create Advertisement</p>
            <p class="ads-card-sub">Shown to customers on the Game or Menu page</p>
        </div>
    </div>
    <form action="{{ route('admin.ads.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off" id="adsCreateForm">
        @csrf
        <div class="ads-grid">
            <div class="ads-wide">
                <label class="form-label-custom" for="adsTitle">Title *</label>
                <input type="text" id="adsTitle" name="title" class="form-control-custom" required maxlength="255" autocomplete="off">
            </div>
            <div class="ads-wide">
                <label class="form-label-custom" for="adsLink">Link (optional)</label>
                <input type="url" id="adsLink" name="link" class="form-control-custom" maxlength="500" placeholder="https://" autocomplete="off">
            </div>
            <div class="ads-wide">
                <label class="form-label-custom" for="adsDescription">Description</label>
                <textarea id="adsDescription" name="description" class="form-control-custom" rows="3" maxlength="500"></textarea>
            </div>
            <div class="ads-wide">
                <span class="form-label-custom">Image</span>
                <label class="ads-drop" for="adsImage" data-ads-drop>
                    <input type="file" id="adsImage" name="image" class="ads-drop-input" accept="image/*" data-ads-drop-input>
                    <span class="ads-drop-text" data-ads-drop-text>🖼️ Tap to upload an image</span>
                    <img alt="Preview of the chosen image" data-ads-drop-preview hidden>
                    <span class="ads-drop-hint">JPG, PNG or WebP, up to 2 MB</span>
                </label>
            </div>
            <div class="ads-wide">
                <fieldset class="ads-place-pick">
                    <legend class="form-label-custom">Placement *</legend>
                    <label class="ads-place-opt"><input type="radio" name="placement" value="game" required checked> <span aria-hidden="true">🎮</span> Game Page</label>
                    <label class="ads-place-opt"><input type="radio" name="placement" value="menu" required> <span aria-hidden="true">📋</span> Menu Page</label>
                </fieldset>
                <small class="ads-hint">Menu Page shows as a popup, once per visit</small>
            </div>
            <div>
                <label class="form-label-custom" for="adsStartsAt">Start Date</label>
                <input type="date" id="adsStartsAt" name="starts_at" class="form-control-custom">
            </div>
            <div>
                <label class="form-label-custom" for="adsEndsAt">End Date</label>
                <input type="date" id="adsEndsAt" name="ends_at" class="form-control-custom">
            </div>
            {{-- Owner: global by default, or one branch. A supervisor has one
                 legal answer, so they see it (disabled, and with no name — it is
                 never posted); promotionBranchIdFor() scopes their ad from
                 their account. --}}
            <div class="ads-wide">
                <label class="form-label-custom" for="adsBranch">Branch Scope</label>
                @if($lockedBranchId === null)
                <select id="adsBranch" name="branch_id" class="form-control-custom">
                    <option value="">All Branches (global)</option>
                    @foreach(($branches ?? collect()) as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
                <small class="ads-hint">Leave as All Branches for a company-wide campaign</small>
                @else
                <select id="adsBranch" class="form-control-custom" disabled>
                    <option>{{ $ownBranchName }}</option>
                </select>
                <small class="ads-hint">This ad will be scoped to your own branch.</small>
                @endif
            </div>
        </div>
        <div class="ads-actions">
            <button type="reset" class="ads-btn-ghost">Clear</button>
            <button type="submit" class="btn-primary-custom">
                <i class="bi bi-megaphone"></i> Publish Ad
            </button>
        </div>
    </form>
</div>

<div class="ads-card" data-testid="ad-list-card">
    <div class="ads-card-head">
        <div>
            <p class="ads-card-title"><span aria-hidden="true">🗂️</span> Advertisements <span class="ads-count" id="adsCount" data-testid="ad-count">{{ count($ads) }}</span></p>
            <p class="ads-card-sub">Active, inactive and expired campaigns</p>
        </div>
    </div>

    {{-- Search + filters. A plain GET form, so a filtered list can be reloaded
         and bookmarked, and with no JavaScript the Search button (noscript)
         submits it. With JavaScript, the script below fetches the same URL in
         the background and swaps only #adsResults. Display only — the viewer's
         own scope is applied before any of this. --}}
    <form action="{{ route('admin.ads') }}" method="GET" class="ads-toolbar" role="search" data-testid="ad-filters">
        {{-- The owner's "Viewing:" branch (the bar at the top of the page). Not
             a filter: "Clear filters" keeps it. Absent for a supervisor. --}}
        @if($lockedBranchId === null)
            <input type="hidden" name="branch" value="{{ $viewingBranchId ?? 'all' }}" data-ads-viewing>
        @endif
        <label class="ads-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="100"
                placeholder="Search title..." aria-label="Search title or description">
        </label>
        <select name="placement" class="form-control-custom ads-filter" aria-label="Filter by placement">
            <option value="">All placements</option>
            <option value="game" @selected($filters['placement'] === 'game')>Game Page</option>
            <option value="menu" @selected($filters['placement'] === 'menu')>Menu Page</option>
        </select>
        <select name="status" class="form-control-custom ads-filter" aria-label="Filter by status">
            <option value="">All statuses</option>
            @foreach(['live' => 'Live', 'inactive' => 'Inactive', 'expired' => 'Expired', 'scheduled' => 'Scheduled'] as $statusKey => $statusLabel)
                <option value="{{ $statusKey }}" @selected($filters['status'] === $statusKey)>{{ $statusLabel }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="ads-btn-ghost">Search</button></noscript>
        <a href="{{ route('admin.ads') }}" class="ads-clear-filters" id="adsClearFilters" data-ads-clear @if(! $filtersOn) hidden @endif>Clear filters</a>
    </form>

    {{-- Shown only when a background update fails; the previous list stays. --}}
    <p class="ads-error" id="adsError" role="alert" hidden>
        Couldn't update the list. Showing the previous results.
        <button type="button" class="ads-error-retry" id="adsRetry">Try again</button>
    </p>

    @include('admin.partials.ad-list-results', ['ads' => $ads, 'filters' => $filters, 'adStats' => $adStats])
</div>

{{-- The ⋮ menus. One open at a time, positioned against the viewport (fixed)
     so the table's own scrollbox never clips them; display:none when closed.
     Closes on an outside click, Escape, a resize, a background list refresh,
     or a real scroll — but not in the first moments after it opens (a scroll
     already in flight used to close a fixed popover the frame after it
     opened). --}}
<script>
    (function () {
        var openBtn = null;
        var openedAt = 0;

        function menuOf(btn) { return document.getElementById(btn.getAttribute('aria-controls')); }

        function closeMenu(returnFocus) {
            if (!openBtn) return;
            var menu = menuOf(openBtn);
            if (menu) menu.hidden = true;
            openBtn.setAttribute('aria-expanded', 'false');
            if (returnFocus) openBtn.focus();
            openBtn = null;
        }

        function openMenu(btn) {
            closeMenu(false);
            var menu = menuOf(btn);
            if (!menu) return;

            menu.hidden = false;
            var rect = btn.getBoundingClientRect();
            var width = menu.offsetWidth;
            var height = menu.offsetHeight;
            var left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
            var top = rect.bottom + 4;
            if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 4);
            menu.style.left = left + 'px';
            menu.style.top = top + 'px';

            btn.setAttribute('aria-expanded', 'true');
            openBtn = btn;
            openedAt = Date.now();

            var first = menu.querySelector('[role="menuitem"]:not([disabled])');
            if (first) first.focus();
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-ads-menu-btn]') : null;
            if (btn) {
                if (btn === openBtn) { closeMenu(false); } else { openMenu(btn); }
                return;
            }
            if (openBtn && !(e.target.closest && e.target.closest('.ads-menu'))) closeMenu(false);
            // A choice inside the menu that does not navigate (Edit opens the modal).
            else if (openBtn && e.target.closest && e.target.closest('button[onclick]')) closeMenu(false);
        });

        document.addEventListener('keydown', function (e) {
            if (!openBtn) return;
            if (e.key === 'Escape') { e.preventDefault(); closeMenu(true); return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                var items = Array.prototype.slice.call(menuOf(openBtn).querySelectorAll('[role="menuitem"]:not([disabled])'));
                if (!items.length) return;
                e.preventDefault();
                var i = items.indexOf(document.activeElement);
                i = e.key === 'ArrowDown' ? (i + 1) % items.length : (i - 1 + items.length) % items.length;
                items[i].focus();
            }
        });

        window.addEventListener('resize', function () { closeMenu(false); });
        document.addEventListener('ads:before-swap', function () { closeMenu(false); });
        window.addEventListener('scroll', function () {
            if (openBtn && Date.now() - openedAt > 300) closeMenu(false);
        }, true);
    })();
</script>

{{-- Filter without a page reload — the Vouchers tab's mechanism. A change to
     the search box, either dropdown or the "Viewing:" bar fetches the SAME
     address (same controller, middleware and scope) with the header
     X-Ads-Fragment: 1, and only #adsResults is swapped; the stat cards are
     updated from the counts it carries. The switches and menus inside it
     need no re-wiring: each is one delegated document listener. --}}
<script>
    (function () {
        var form = document.querySelector('[data-testid="ad-filters"]');
        var region = document.getElementById('adsResults');
        var errorBox = document.getElementById('adsError');
        if (!form || !region || !errorBox || !window.fetch || !window.URL || !window.URLSearchParams || !window.AbortController) {
            return;
        }

        var countEl = document.getElementById('adsCount');
        var clearEl = document.getElementById('adsClearFilters');
        var viewingEl = form.querySelector('[data-ads-viewing]');
        var DEBOUNCE_MS = 300;
        var timer = null;
        var latest = 0;
        var inflight = null;
        var lastUrl = null;

        function filterUrl() {
            var url = new URL(window.location.href);
            ['q', 'placement', 'status'].forEach(function (name) {
                var value = form.elements[name].value.trim();
                if (value === '') { url.searchParams.delete(name); } else { url.searchParams.set(name, value); }
            });
            if (viewingEl) { url.searchParams.set('branch', viewingEl.value || 'all'); }
            return url;
        }

        function updateStats(fresh) {
            ['total', 'live', 'game', 'menu'].forEach(function (key) {
                var value = fresh.dataset['stat' + key.charAt(0).toUpperCase() + key.slice(1)];
                var card = document.querySelector('[data-ad-stat="' + key + '"]');
                if (card && value !== undefined) { card.textContent = Number(value).toLocaleString('en-US'); }
            });
        }

        function setBusy(on) {
            region.classList.toggle('is-loading', on);
            region.setAttribute('aria-busy', on ? 'true' : 'false');
        }

        function load(url) {
            clearTimeout(timer);
            if (inflight) { inflight.abort(); }
            var mine = ++latest;
            var controller = new AbortController();
            inflight = controller;
            lastUrl = url;
            setBusy(true);

            fetch(url.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Ads-Fragment': '1', 'Accept': 'text/html' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            })
                .then(function (response) {
                    if (mine !== latest) { return null; }
                    if (response.redirected) {
                        window.location.assign(url.href);
                        return null;
                    }
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.text();
                })
                .then(function (html) {
                    if (html === null || mine !== latest) { return; }

                    var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('adsResults');
                    if (!fresh) { throw new Error('no results region in response'); }

                    document.dispatchEvent(new CustomEvent('ads:before-swap'));
                    region.innerHTML = fresh.innerHTML;
                    region.dataset.total = fresh.dataset.total;
                    region.dataset.filtersOn = fresh.dataset.filtersOn;
                    if (countEl) { countEl.textContent = fresh.dataset.total; }
                    if (clearEl) { clearEl.hidden = fresh.dataset.filtersOn !== '1'; }
                    updateStats(fresh);

                    window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
                    errorBox.hidden = true;
                    setBusy(false);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') { return; }
                    if (mine !== latest) { return; }
                    errorBox.hidden = false;
                    setBusy(false);
                });
        }

        function applyNow() { load(filterUrl()); }

        form.elements.q.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(applyNow, DEBOUNCE_MS);
        });
        form.elements.placement.addEventListener('change', applyNow);
        form.elements.status.addEventListener('change', applyNow);
        form.addEventListener('submit', function (e) { e.preventDefault(); applyNow(); });

        document.addEventListener('click', function (e) {
            var clear = e.target.closest ? e.target.closest('[data-ads-clear]') : null;
            if (!clear || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            e.preventDefault();
            form.elements.q.value = '';
            form.elements.placement.value = '';
            form.elements.status.value = '';
            applyNow();
        });

        document.getElementById('adsRetry').addEventListener('click', function () {
            load(lastUrl || filterUrl());
        });

        // The "Viewing:" bar (owner only): switch the list and the cards here,
        // in the background. The server remembers the branch for the other tabs.
        if (viewingEl) {
            window.pcBranchSelectHandler = function (value, select) {
                viewingEl.value = value || 'all';
                var label = document.querySelector('.pc-branchbar .value');
                var option = select && select.options[select.selectedIndex];
                if (label && option) { label.textContent = option.text.trim(); }
                applyNow();
                return true;
            };
        }
    })();
</script>

{{-- The Active switch. ONE listener on the document, so the switches keep
     working after any background list refresh. A switch the viewer may change
     carries data-url (admin.ads.toggle); one they may not is disabled, has no
     URL, and is refused by the server anyway.

     The request SETS the state the switch now shows. Until the server answers,
     both copies of that ad's switch (table row and phone card) are held; then
     they show what was saved, with the new Status badge and "Live now" count.
     If the server refuses, the switch goes back and the reason is shown. --}}
<div class="ads-toast" id="adsToast" role="status" aria-live="polite"></div>
<script>
    (function () {
        var toastEl = document.getElementById('adsToast');
        var toastTimer = null;
        var STATUS_LABELS = { live: 'Live', inactive: 'Inactive', expired: 'Expired', scheduled: 'Scheduled' };

        function toast(message, isError) {
            toastEl.textContent = message;
            toastEl.classList.toggle('is-error', !!isError);
            toastEl.setAttribute('role', isError ? 'alert' : 'status');
            toastEl.classList.add('is-shown');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toastEl.classList.remove('is-shown'); }, isError ? 6000 : 2400);
        }

        function each(selector, fn) { Array.prototype.forEach.call(document.querySelectorAll(selector), fn); }

        function hold(id, on, busy) {
            each('[data-ad-active="' + id + '"]', function (input) {
                input.checked = on;
                input.disabled = busy || !input.getAttribute('data-url');
                var wrap = input.closest('.pc-switch');
                if (wrap) { wrap.classList.toggle('is-busy', busy); }
            });
        }

        function why(response, body) {
            if (response.redirected) {
                return /\/admin\/login/.test(response.url)
                    ? 'Your session has ended. Please sign in again.'
                    : "You don't have permission to change this ad.";
            }
            if (response.status === 403) { return body.message || 'Company-wide ads can only be managed by the owner.'; }
            if (response.status === 404) { return 'This ad is not in your branch, or it was deleted. Refresh the list.'; }
            if (response.status === 419) { return 'Your session expired. Reloading the page…'; }
            if (response.status === 422) { return body.message || 'That change was not accepted.'; }
            if (response.status === 429) { return 'Too many changes at once. Wait a moment and try again.'; }
            return "Couldn't turn the ad " + (response.status ? 'on or off (error ' + response.status + ')' : 'on or off') + '. Nothing was changed.';
        }

        document.addEventListener('change', function (e) {
            var input = e.target.closest ? e.target.closest('[data-ad-active]') : null;
            if (!input) { return; }

            var id = input.getAttribute('data-ad-active');
            var url = input.getAttribute('data-url');
            var want = input.checked;
            if (!url) { input.checked = !want; return; }

            hold(id, want, true);

            fetch(url, {
                method: 'PUT',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ is_active: want })
            })
                .then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (body) {
                        if (!response.ok || response.redirected || typeof body.is_active !== 'boolean') {
                            throw new Error(why(response, body));
                        }
                        return body;
                    });
                })
                .then(function (body) {
                    hold(id, body.is_active, false);
                    each('[data-ad-status="' + id + '"]', function (badge) {
                        badge.className = 'ads-status is-' + body.status;
                        badge.textContent = STATUS_LABELS[body.status] || body.status;
                    });
                    each('[data-ad-row="' + id + '"]', function (row) { row.setAttribute('data-status', body.status); });
                    if (body.stats) {
                        Object.keys(body.stats).forEach(function (key) {
                            var card = document.querySelector('[data-ad-stat="' + key + '"]');
                            if (card) { card.textContent = Number(body.stats[key]).toLocaleString('en-US'); }
                        });
                    }
                    toast(body.message, false);
                })
                .catch(function (err) {
                    hold(id, !want, false);
                    toast(err && err.message && err.name !== 'TypeError' ? err.message : "Couldn't reach the server. The ad was not changed.", true);
                });
        });
    })();
</script>

{{-- The image drop zone: a preview of the chosen file, "Tap to change", and
     a dropped file goes into the same input. Clear empties it. --}}
<script>
    (function () {
        var form = document.getElementById('adsCreateForm');
        var drop = form && form.querySelector('[data-ads-drop]');
        if (!drop) { return; }
        var input = drop.querySelector('[data-ads-drop-input]');
        var text = drop.querySelector('[data-ads-drop-text]');
        var preview = drop.querySelector('[data-ads-drop-preview]');
        var objectUrl = null;

        function show(file) {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            if (file && /^image\//.test(file.type)) {
                objectUrl = URL.createObjectURL(file);
                preview.src = objectUrl;
                preview.hidden = false;
                text.textContent = '🔁 Tap to change image';
            } else {
                preview.removeAttribute('src');
                preview.hidden = true;
                text.textContent = '🖼️ Tap to upload an image';
            }
        }

        input.addEventListener('change', function () { show(input.files && input.files[0]); });
        form.addEventListener('reset', function () { setTimeout(function () { show(null); }, 0); });

        ['dragenter', 'dragover'].forEach(function (type) {
            drop.addEventListener(type, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            drop.addEventListener(type, function () { drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (e) {
            e.preventDefault();
            if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) { return; }
            try { input.files = e.dataTransfer.files; } catch (err) { return; }
            show(input.files[0]);
        });
    })();
</script>

{{-- Edit Ad Modal --}}
<div class="pchy-modal-overlay" id="editAdModal">
    <div class="pchy-modal-box" role="dialog" aria-modal="true" aria-labelledby="editAdModalTitle">
        <div class="pchy-modal-hd">
            <p class="pchy-modal-title" id="editAdModalTitle"><span aria-hidden="true">✏️</span> Edit Advertisement</p>
            <button type="button" class="pchy-modal-close" onclick="closeEditAdModal()" aria-label="Close">&times;</button>
        </div>

        <form id="editAdForm" method="POST" enctype="multipart/form-data" autocomplete="off">
            @csrf
            @method('PUT')

            <div class="pchy-modal-preview-wrap">
                <img id="editAdPreview" class="pchy-modal-preview" src="" alt="" style="display:none;">
                <div id="editAdPreviewPh" class="pchy-modal-preview-ph" aria-hidden="true">🖼️</div>
                <span class="pchy-modal-preview-cap" id="editAdPreviewCap">Current image</span>
            </div>

            <div class="ads-grid is-two">
                <div class="ads-full">
                    <label class="form-label-custom" for="editAdTitle">Title *</label>
                    <input type="text" name="title" id="editAdTitle" class="form-control-custom" required maxlength="255" autocomplete="off">
                </div>
                <div class="ads-full">
                    <label class="form-label-custom" for="editAdDescription">Description</label>
                    <textarea name="description" id="editAdDescription" class="form-control-custom" rows="3" maxlength="500"></textarea>
                </div>
                <div class="ads-full">
                    <label class="form-label-custom" for="editAdLink">Link (optional)</label>
                    <input type="url" name="link" id="editAdLink" class="form-control-custom" maxlength="500" placeholder="https://" autocomplete="off">
                </div>
                <div>
                    <label class="form-label-custom" for="editAdPlacement">Placement *</label>
                    <select name="placement" id="editAdPlacement" class="form-control-custom" required>
                        {{-- Empty value = "not chosen", which `required` refuses. It is
                             the placeholder-label option, and it is selected only for
                             an ad saved with a placement that no longer exists (see
                             openEditAdModal), so that ad opens without an error but
                             cannot be saved until it is pointed at a live placement. --}}
                        <option value="" id="editAdPlacementLegacy" hidden>Choose a placement…</option>
                        <option value="game">Game Page</option>
                        <option value="menu">Menu Page</option>
                    </select>
                    <small id="editAdPlacementLegacyNote" style="display:none;font-size:0.7rem;color:#C0392B;">
                        This ad used a placement that no longer exists and is not shown anywhere. Choose Game or Menu to use it again.
                    </small>
                </div>
                {{-- Owner only as a choice: updateAd() re-derives a supervisor's
                     branch_id from their account whatever the form posts, so
                     they see their branch, disabled and never posted. --}}
                <div>
                    <label class="form-label-custom" for="editAdBranch">Branch Scope</label>
                    @if($lockedBranchId === null)
                    <select name="branch_id" id="editAdBranch" class="form-control-custom">
                        <option value="">All Branches (global)</option>
                        @foreach(($branches ?? collect()) as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @else
                    <select class="form-control-custom" disabled aria-label="Branch Scope">
                        <option>{{ $ownBranchName }}</option>
                    </select>
                    @endif
                </div>
                <div>
                    <label class="form-label-custom" for="editAdStartsAt">Start Date</label>
                    <input type="date" name="starts_at" id="editAdStartsAt" class="form-control-custom">
                </div>
                <div>
                    <label class="form-label-custom" for="editAdEndsAt">End Date</label>
                    <input type="date" name="ends_at" id="editAdEndsAt" class="form-control-custom">
                </div>
                <div class="ads-full">
                    <label class="form-label-custom" for="editAdImage">Replace Image (optional)</label>
                    <input type="file" name="image" id="editAdImage" class="form-control-custom" accept="image/*">
                </div>
            </div>

            <div class="pchy-modal-foot">
                <button type="button" class="ads-btn-ghost" onclick="closeEditAdModal()">Cancel</button>
                <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    var editAdCurrentImage = '';

    function showEditAdPreview(src, caption) {
        var preview = document.getElementById('editAdPreview');
        var placeholder = document.getElementById('editAdPreviewPh');
        if (src) {
            preview.src = src;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
        } else {
            preview.style.display = 'none';
            placeholder.style.display = 'grid';
        }
        document.getElementById('editAdPreviewCap').textContent = caption;
    }

    function openEditAdModal(btn) {
        document.getElementById('editAdForm').action = '{{ url('admin/ads') }}/' + btn.dataset.id;
        document.getElementById('editAdTitle').value = btn.dataset.title || '';
        document.getElementById('editAdDescription').value = btn.dataset.description || '';
        document.getElementById('editAdLink').value = btn.dataset.link || '';
        // Only the live placements exist as options. An old cart/orders ad
        // falls back to the "Choose a placement…" option and says why, rather
        // than leaving the select blank with no explanation.
        var placementField = document.getElementById('editAdPlacement');
        var placementNote = document.getElementById('editAdPlacementLegacyNote');
        var isLive = btn.dataset.placement === 'game' || btn.dataset.placement === 'menu';
        placementField.value = isLive ? btn.dataset.placement : '';
        placementNote.style.display = isLive ? 'none' : 'block';
        document.getElementById('editAdStartsAt').value = btn.dataset.startsAt || '';
        document.getElementById('editAdEndsAt').value = btn.dataset.endsAt || '';
        document.getElementById('editAdImage').value = '';

        // Absent for a supervisor — the field is not rendered for them.
        var branchField = document.getElementById('editAdBranch');
        if (branchField) {
            branchField.value = btn.dataset.branch || '';
        }

        editAdCurrentImage = btn.dataset.image || '';
        showEditAdPreview(editAdCurrentImage, 'Current image');

        document.getElementById('editAdModal').classList.add('show');
        document.getElementById('editAdTitle').focus();
    }

    function closeEditAdModal() {
        document.getElementById('editAdModal').classList.remove('show');
    }

    (function () {
        var modal = document.getElementById('editAdModal');
        var file = document.getElementById('editAdImage');
        var objectUrl = null;

        // A chosen replacement shows in place of the current image.
        file.addEventListener('change', function () {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            var chosen = file.files && file.files[0];
            if (chosen && /^image\//.test(chosen.type)) {
                objectUrl = URL.createObjectURL(chosen);
                showEditAdPreview(objectUrl, 'New image (saved when you press Save Changes)');
            } else {
                showEditAdPreview(editAdCurrentImage, 'Current image');
            }
        });

        // Close on the backdrop and on Escape, as in the reference.
        modal.addEventListener('click', function (e) { if (e.target === modal) { closeEditAdModal(); } });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('show')) { closeEditAdModal(); }
        });
    })();
</script>

@endsection

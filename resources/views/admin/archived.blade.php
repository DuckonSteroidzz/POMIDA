@extends('admin.layout')

@section('title', 'Archived Items - Peachy Admin')

@section('content')

@php
    $adminUser = Auth::guard('admin')->user();
@endphp

<link href="/vendor/gfonts.css" rel="stylesheet">

<style>
    .pchy{--p1:#F8D7B0;--p2:#F6B49B;--p3:#EF8585;--p4:#F4845F;--p5:#C0392B;--p6:#8B1A1A;font-family:'Karla',system-ui,sans-serif;color:#4a3b36}
    .pchy *{box-sizing:border-box}

    .pchy-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1.1rem;padding-bottom:.85rem;border-bottom:2px dashed rgba(244,132,95,.35)}
    .pchy-title{font-family:'Fraunces',Georgia,serif;font-size:1.7rem;font-weight:600;line-height:1.15;margin:0;color:var(--p6)}
    .pchy-chips{display:flex;gap:.45rem;flex-wrap:wrap}
    .pchy-chip{background:linear-gradient(135deg,var(--p1),var(--p2));color:var(--p6);font-size:.74rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:.4rem .7rem;border-radius:999px;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem}

    .pchy-alert{background:#f2fbf4;border:1px solid rgba(34,120,60,.25);border-left:5px solid #2e7d46;color:#20502f;padding:.75rem 1rem;border-radius:12px;font-size:.85rem;margin-bottom:1.1rem}
    .pchy-alert-bad{background:#fff2f0;border-color:rgba(192,57,43,.28);border-left-color:var(--p5);color:var(--p6)}

    /* Success only (never the error/refusal alert above, which stays put
       until the admin reads it) — auto-dismisses and gets a close button.
       Scoped to its own class rather than added to .pchy-alert itself,
       because .pchy-alert-bad reuses that same base class for a list of
       several stacked <div> error lines; turning the base class into a
       flex row would have rearranged those into a row too. */
    .pchy-alert-success{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;transition:opacity .4s ease,transform .4s ease}
    .pchy-alert-close{flex-shrink:0;background:none;border:0;margin:-.1rem -.2rem 0 0;padding:.15rem .3rem;line-height:1;color:inherit;opacity:.5;cursor:pointer;font-size:.85rem;border-radius:6px}
    .pchy-alert-close:hover,.pchy-alert-close:focus-visible{opacity:1;background:rgba(46,125,70,.1)}

    .pchy-card{background:#fff;border:1px solid rgba(246,180,155,.45);border-radius:18px;padding:1.1rem;box-shadow:0 10px 26px -18px rgba(139,26,26,.35);margin-bottom:.85rem}
    .pchy-card-hd{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem}
    .pchy-card-t{font-family:'Fraunces',Georgia,serif;font-size:1.05rem;font-weight:600;color:var(--p6);margin:0;display:flex;align-items:center;gap:.5rem}
    .pchy-ico{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:linear-gradient(135deg,var(--p2),var(--p4));color:#fff;font-size:.85rem}
    .pchy-count{font-size:.72rem;font-weight:700;color:var(--p5);background:rgba(248,215,176,.55);padding:.25rem .55rem;border-radius:999px}

    .pchy-row{display:flex;align-items:center;justify-content:space-between;gap:.8rem;flex-wrap:wrap;background:#fffaf6;border:1.5px solid rgba(246,180,155,.42);border-radius:13px;padding:.65rem .85rem;margin-bottom:.45rem}
    .pchy-row:last-child{margin-bottom:0}
    .pchy-row-main{min-width:0}
    .pchy-row-name{font-size:.9rem;font-weight:700;color:#463430;margin:0}
    .pchy-row-meta{font-size:.74rem;color:#9a837b;margin:.2rem 0 0}
    /* .pchy-row-warn, .pchy-row-actions and .pchy-forcedel are Deleted
       Inventory's (admin/inventory-deleted.blade.php), copied as-is so the
       two permanent-delete screens look and read the same. */
    .pchy-row-warn{font-size:.72rem;color:var(--p5);font-weight:700;margin:.25rem 0 0}
    .pchy-row-note{font-size:.72rem;color:#9a837b;font-weight:600;margin:.25rem 0 0}

    .pchy-row-actions{display:flex;gap:.4rem;flex-wrap:wrap}
    .pchy-restore{font-family:'Karla',sans-serif;font-size:.8rem;font-weight:700;border:0;cursor:pointer;border-radius:10px;padding:.5rem .85rem;color:#fff;background:linear-gradient(135deg,var(--p4),var(--p5));display:inline-flex;align-items:center;gap:.4rem}
    .pchy-restore:hover{filter:brightness(1.06)}
    .pchy-forcedel{font-family:'Karla',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;border-radius:10px;padding:.5rem .85rem;color:var(--p5);background:#fff;border:1.5px solid var(--p5);display:inline-flex;align-items:center;gap:.4rem}
    .pchy-forcedel:hover{background:var(--p5);color:#fff}

    .pchy-empty{text-align:center;color:#a08d86;font-size:.85rem;padding:1.4rem .5rem}
    .pchy-empty i{display:block;font-size:1.6rem;margin-bottom:.4rem;color:rgba(244,132,95,.55)}
</style>

<div class="pchy">

    {{-- The intro paragraph and the "Archived is not the same as Unavailable"
         callout that used to sit here are gone on request — the owner found
         them cluttered once the page had been used a few times. The one
         explanation that stays is the empty-state card further down, which
         only shows up when there is genuinely nothing archived, exactly where
         a first-time user needs it. --}}
    <div class="pchy-head">
        <h1 class="pchy-title">Archived Items</h1>
        <div class="pchy-chips">
            {{-- Menu Items is Y | Y | Y, so this one is ungated. Add-ons and
                 Categories are "Manage Menu Options/Add-ons" and "Manage
                 Categories", both Y | Y | N — viewing the archive is shared
                 with staff, but these two chips lead to manager-only screens
                 and bounced a staff member who clicked them. --}}
            <a href="{{ route('admin.menu-items') }}" class="pchy-chip"><i class="bi bi-arrow-left"></i> Menu Items</a>
            @if($adminUser && $adminUser->isManager())
            <a href="{{ route('admin.menu-options') }}" class="pchy-chip"><i class="bi bi-plus-square"></i> Add-ons</a>
            <a href="{{ route('admin.add-category') }}" class="pchy-chip"><i class="bi bi-tags"></i> Categories</a>
            @endif
        </div>
    </div>

    {{-- Auto-dismisses after ~5s and has a close button (2026-09-24) — the
         error/refusal alert just below is untouched and stays until the
         admin reads it; only this one is a "nice to know" that already got
         its detail across in the confirm() dialog before the click. --}}
    @if(session('success'))
        <div class="pchy-alert pchy-alert-success" id="pchySuccessAlert" data-testid="archived-success-alert">
            <span>{{ session('success') }}</span>
            <button type="button" class="pchy-alert-close" id="pchySuccessAlertClose" aria-label="Dismiss">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    @endif

    @if($errors->any())
        <div class="pchy-alert pchy-alert-bad">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @php
        $sections = [
            ['label' => 'Menu items', 'icon' => 'bi-cup-hot', 'type' => 'menu-item', 'rows' => $menuItems],
            ['label' => 'Add-ons', 'icon' => 'bi-plus-square', 'type' => 'menu-option', 'rows' => $menuOptions],
            ['label' => 'Categories', 'icon' => 'bi-tags', 'type' => 'category', 'rows' => $categories],
            ['label' => 'Subcategories', 'icon' => 'bi-diagram-3', 'type' => 'subcategory', 'rows' => $subcategories],
        ];
        $totalArchived = collect($sections)->sum(fn ($s) => $s['rows']->count());
    @endphp

    @if($totalArchived === 0)
        <div class="pchy-card">
            <div class="pchy-empty">
                <i class="bi bi-archive"></i>
                Nothing is archived. Deleted menu items always come here first, where they can be
                restored. Add-ons, categories and subcategories that were never used are deleted
                outright, so they will not appear here.
            </div>
        </div>
    @endif

    @foreach($sections as $section)
        @if($section['rows']->count() > 0)
        <div class="pchy-card">
            <div class="pchy-card-hd">
                <h2 class="pchy-card-t">
                    <span class="pchy-ico"><i class="bi {{ $section['icon'] }}"></i></span>
                    {{ $section['label'] }}
                </h2>
                <span class="pchy-count">{{ $section['rows']->count() }} archived</span>
            </div>

            @foreach($section['rows'] as $row)
                @php
                    // Permanent Delete: owner only, menu items only, and never
                    // while the item is on an open (pending/preparing/serving)
                    // order. A sold item CAN go: its past order lines are kept
                    // and only their link is cut. The counts come from
                    // withCount() in showArchivedCatalogue(), which only runs
                    // for the owner — the same person this block is for — and
                    // are the same rules permanentlyDeleteMenuItem() re-checks
                    // under lock.
                    $offersPermanentDelete = $canPermanentlyDelete && $section['type'] === 'menu-item';
                    $orderLines    = $offersPermanentDelete ? (int) $row->order_lines_count : 0;
                    $openLines     = $offersPermanentDelete ? (int) $row->open_lines_count : 0;
                    $uncostedLines = $offersPermanentDelete ? (int) $row->uncosted_lines_count : 0;
                    $optionLinks   = $offersPermanentDelete ? (int) $row->option_links_count : 0;
                    $recipeLines   = $offersPermanentDelete ? (int) $row->recipe_lines_count : 0;
                    $optionLinksLabel = $optionLinks . ' ' . \Illuminate\Support\Str::plural('add-on assignment', $optionLinks);
                    $recipeLinesLabel = $recipeLines . ' ' . \Illuminate\Support\Str::plural('recipe ingredient', $recipeLines);
                    $pastLinesLabel   = $orderLines . ' past order ' . \Illuminate\Support\Str::plural('line', $orderLines);

                    // Plain strings here; the attribute below is written with
                    // Js::from(), which escapes quotes, apostrophes, <, >, &
                    // and newlines — a name can no longer break out of the
                    // dialog (addslashes() left a newline able to, and a
                    // broken onsubmit submits the form without asking).
                    if ($orderLines > 0) {
                        $forceDeleteConfirm = 'Permanently delete "' . $row->name . '"? Its ' . $pastLinesLabel
                            . ($orderLines === 1 ? ' is' : ' are') . ' kept — receipts and sales reports will still show "'
                            . $row->name . '". Only the link to this menu item is removed. This also removes '
                            . $optionLinksLabel . ' and ' . $recipeLinesLabel . '.'
                            . ($uncostedLines > 0
                                ? ' ' . $uncostedLines . ' past ' . \Illuminate\Support\Str::plural('line', $uncostedLines)
                                    . ' had no recorded ingredient cost; today\'s estimated cost will be saved on '
                                    . ($uncostedLines === 1 ? 'it' : 'them') . ' and marked as estimated.'
                                : '')
                            . ' This cannot be undone.';
                    } else {
                        // Unsold: the Phase 1 wording, unchanged.
                        $forceDeleteConfirm = 'Permanently delete ' . $row->name . '? This also removes '
                            . $optionLinksLabel . ' and ' . $recipeLinesLabel . '. This cannot be undone.';
                    }
                @endphp
                <div class="pchy-row">
                    <div class="pchy-row-main">
                        <p class="pchy-row-name">{{ $row->name }}</p>
                        <p class="pchy-row-meta">
                            Archived
                            @if($row->archived_at)
                                {{ $row->archived_at->format('M d, Y') }} at {{ $row->archived_at->format('g:i A') }}
                            @else
                                (date not recorded)
                            @endif

                            @if($section['type'] === 'menu-item')
                                &middot; ₱{{ number_format($row->price, 2) }}
                                @if($row->category) &middot; {{ $row->category->name }} @endif
                                @if($row->branch) &middot; {{ $row->branch->name }} @endif
                            @elseif($section['type'] === 'menu-option')
                                &middot; +₱{{ number_format($row->additional_price, 2) }}
                            @elseif($section['type'] === 'subcategory')
                                @if($row->category) &middot; under {{ $row->category->name }} @endif
                            @endif
                        </p>
                        @if($offersPermanentDelete)
                            @if($openLines > 0)
                                <p class="pchy-row-note" data-testid="archived-open-order-note">
                                    <i class="bi bi-hourglass-split"></i>
                                    On an open order — permanent delete is available once it is completed or cancelled.
                                </p>
                            @elseif($orderLines > 0)
                                <p class="pchy-row-note" data-testid="archived-history-note">
                                    <i class="bi bi-receipt"></i>
                                    On {{ $pastLinesLabel }} — those receipts and sales figures are kept if you permanently delete it.
                                </p>
                            @else
                                <p class="pchy-row-warn">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    Permanently deleting this also removes {{ $optionLinksLabel }}
                                    and {{ $recipeLinesLabel }}.
                                </p>
                            @endif
                        @endif
                    </div>

                    {{-- Restoring is the destructive half of this feature and stays
                         admin-only, matching the previous round's decision (the
                         admin.archived.restore route is still admin-only middleware).
                         Staff can now see this page — that is the fix for this task —
                         but they get a plain status note here instead of a button that
                         a role check would reject anyway. --}}
                    @if($adminUser && $adminUser->role === 'admin')
                    <div class="pchy-row-actions">
                        <form action="{{ route('admin.archived.restore', [$section['type'], $row->id]) }}"
                            method="POST"
                            onsubmit="return confirm({{ \Illuminate\Support\Js::from('Restore ' . $row->name . ' to the main list?') }})"
                            style="margin:0;">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="pchy-restore">
                                <i class="bi bi-arrow-counterclockwise"></i> Restore
                            </button>
                        </form>

                        {{-- Owner only (admin.archived.menu-item.force-delete is
                             role:admin, re-checked in the controller). Not drawn at
                             all while the item is on an open order — the server
                             refuses it anyway. --}}
                        @if($offersPermanentDelete && $openLines === 0)
                        <form action="{{ route('admin.archived.menu-item.force-delete', $row->id) }}"
                            method="POST"
                            onsubmit="return confirm({{ \Illuminate\Support\Js::from($forceDeleteConfirm) }})"
                            style="margin:0;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="pchy-forcedel">
                                <i class="bi bi-trash3-fill"></i> Permanently Delete
                            </button>
                        </form>
                        @endif
                    </div>
                    @else
                    <span style="font-size:.76rem;color:#a08d86;font-weight:600;white-space:nowrap;">
                        <i class="bi bi-eye"></i> View only
                    </span>
                    @endif
                </div>
            @endforeach
        </div>
        @endif
    @endforeach

</div>

@endsection

@push('scripts')
<script>
    (function () {
        // Success toast only — see the note on .pchy-alert-success above for
        // why the error/refusal alert is never touched by this script. Same
        // fade-then-remove idiom the shared [data-auto-dismiss] toast in
        // admin/layout.blade.php already uses (opacity 0 + a small upward
        // shift, removed once the transition finishes), just with its own
        // ~5s timer and a close button that skips straight to it.
        var alertEl = document.getElementById('pchySuccessAlert');
        if (!alertEl) {
            return;
        }

        var dismissed = false;

        function dismiss() {
            if (dismissed) {
                return;
            }
            dismissed = true;

            alertEl.style.opacity = '0';
            alertEl.style.transform = 'translateY(6px)';
            setTimeout(function () { alertEl.remove(); }, 400);
        }

        var timer = setTimeout(dismiss, 5000);

        var closeBtn = document.getElementById('pchySuccessAlertClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                clearTimeout(timer);
                dismiss();
            });
        }
    })();
</script>
@endpush

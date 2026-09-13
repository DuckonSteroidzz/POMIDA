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

    .pchy-restore{font-family:'Karla',sans-serif;font-size:.8rem;font-weight:700;border:0;cursor:pointer;border-radius:10px;padding:.5rem .85rem;color:#fff;background:linear-gradient(135deg,var(--p4),var(--p5));display:inline-flex;align-items:center;gap:.4rem}
    .pchy-restore:hover{filter:brightness(1.06)}

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

    @if(session('success'))
        <div class="pchy-alert">{{ session('success') }}</div>
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
                Nothing is archived. When you remove something that has never been ordered it is
                deleted outright, so it will not appear here.
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
                    </div>

                    {{-- Restoring is the destructive half of this feature and stays
                         admin-only, matching the previous round's decision (the
                         admin.archived.restore route is still admin-only middleware).
                         Staff can now see this page — that is the fix for this task —
                         but they get a plain status note here instead of a button that
                         a role check would reject anyway. --}}
                    @if($adminUser && $adminUser->role === 'admin')
                    <form action="{{ route('admin.archived.restore', [$section['type'], $row->id]) }}"
                        method="POST"
                        onsubmit="return confirm('Restore {{ addslashes($row->name) }} to the main list?')"
                        style="margin:0;">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="pchy-restore">
                            <i class="bi bi-arrow-counterclockwise"></i> Restore
                        </button>
                    </form>
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

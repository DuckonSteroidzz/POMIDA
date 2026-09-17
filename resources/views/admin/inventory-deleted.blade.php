@extends('admin.layout')

@section('title', 'Deleted Inventory Items - Peachy Admin')

@section('content')

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
    .pchy-row-warn{font-size:.72rem;color:var(--p5);font-weight:700;margin:.25rem 0 0}

    .pchy-row-actions{display:flex;gap:.4rem;flex-wrap:wrap}
    .pchy-restore{font-family:'Karla',sans-serif;font-size:.8rem;font-weight:700;border:0;cursor:pointer;border-radius:10px;padding:.5rem .85rem;color:#fff;background:linear-gradient(135deg,var(--p4),var(--p5));display:inline-flex;align-items:center;gap:.4rem}
    .pchy-restore:hover{filter:brightness(1.06)}
    .pchy-forcedel{font-family:'Karla',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;border-radius:10px;padding:.5rem .85rem;color:var(--p5);background:#fff;border:1.5px solid var(--p5);display:inline-flex;align-items:center;gap:.4rem}
    .pchy-forcedel:hover{background:var(--p5);color:#fff}

    .pchy-empty{text-align:center;color:#a08d86;font-size:.85rem;padding:1.4rem .5rem}
    .pchy-empty i{display:block;font-size:1.6rem;margin-bottom:.4rem;color:rgba(244,132,95,.55)}
</style>

<div class="pchy">

    <div class="pchy-head">
        <h1 class="pchy-title">Deleted Inventory Items</h1>
        <div class="pchy-chips">
            <a href="{{ route('admin.inventory') }}" class="pchy-chip"><i class="bi bi-arrow-left"></i> Inventory</a>
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

    @if($deletedItems->isEmpty())
        <div class="pchy-card">
            <div class="pchy-empty">
                <i class="bi bi-trash3"></i>
                Nothing is deleted. Items you delete from Inventory show up here first, recoverable,
                before they can be permanently removed.
            </div>
        </div>
    @else
        <div class="pchy-card">
            <div class="pchy-card-hd">
                <h2 class="pchy-card-t">
                    <span class="pchy-ico"><i class="bi bi-box-seam"></i></span>
                    Inventory items
                </h2>
                <span class="pchy-count">{{ $deletedItems->count() }} deleted</span>
            </div>

            @foreach($deletedItems as $item)
                @php
                    $recipeLinks = $recipeCounts[$item->id] ?? 0;
                    $movementCount = $movementCounts[$item->id] ?? 0;
                @endphp
                <div class="pchy-row">
                    <div class="pchy-row-main">
                        <p class="pchy-row-name">{{ $item->item_name }}</p>
                        <p class="pchy-row-meta">
                            Deleted
                            @if($item->archived_at)
                                {{ $item->archived_at->format('M d, Y') }} at {{ $item->archived_at->format('g:i A') }}
                            @else
                                (date not recorded)
                            @endif
                            &middot; {{ $item->quantity }} {{ $item->unit }}
                            @if($item->branch) &middot; {{ $item->branch->name }} @endif
                        </p>
                        @if($recipeLinks > 0 || $movementCount > 0)
                            <p class="pchy-row-warn">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                Permanently deleting this will
                                @if($recipeLinks > 0)
                                    remove it from {{ $recipeLinks }} {{ \Illuminate\Support\Str::plural('recipe', $recipeLinks) }}
                                @endif
                                @if($recipeLinks > 0 && $movementCount > 0) and @endif
                                @if($movementCount > 0)
                                    keep {{ $movementCount }} past stock {{ \Illuminate\Support\Str::plural('movement', $movementCount) }} in the log with no live item behind them
                                @endif
                                .
                            </p>
                        @endif
                    </div>

                    <div class="pchy-row-actions">
                        <form action="{{ route('admin.inventory.restore', $item->id) }}"
                            method="POST"
                            onsubmit="return confirm('Restore {{ addslashes($item->item_name) }} to Inventory?')"
                            style="margin:0;">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="pchy-restore">
                                <i class="bi bi-arrow-counterclockwise"></i> Restore
                            </button>
                        </form>

                        <form action="{{ route('admin.inventory.force-delete', $item->id) }}"
                            method="POST"
                            onsubmit="return confirm('Permanently delete {{ addslashes($item->item_name) }}? This cannot be undone.')"
                            style="margin:0;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="pchy-forcedel">
                                <i class="bi bi-trash3-fill"></i> Permanently Delete
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>

@endsection

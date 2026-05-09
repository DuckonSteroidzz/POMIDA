@extends('admin.layout')

@section('title', 'Ads - Peachy Admin')

@section('content')

<p class="page-title">Ads Management</p>

@if(session('success'))
<div class="alert-success-custom">
    <i class="bi bi-check-circle"></i> {{ session('success') }}
</div>
@endif

<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:1rem;">Create New Ad</p>
    <form action="{{ route('admin.ads.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.75rem;margin-bottom:0.75rem;">
            <div>
                <label class="form-label-custom">Title *</label>
                <input type="text" name="title" class="form-control-custom" placeholder="e.g. Summer Promo" required>
            </div>
            <div>
                <label class="form-label-custom">Description</label>
                <input type="text" name="description" class="form-control-custom" placeholder="e.g. 50% off all drinks">
            </div>
            <div>
                <label class="form-label-custom">Image</label>
                <input type="file" name="image" class="form-control-custom" accept="image/*">
            </div>
            <div>
                <label class="form-label-custom">Link (optional)</label>
                <input type="url" name="link" class="form-control-custom" placeholder="https://...">
            </div>
            <div>
                <label class="form-label-custom">Placement *</label>
                <select name="placement" class="form-control-custom" required>
                    <option value="game">Game Page</option>
                    <option value="menu">Menu Page</option>
                    <option value="cart">Cart Page</option>
                    <option value="orders">Orders Page</option>
                </select>
            </div>
            <div>
                <label class="form-label-custom">Start Date</label>
                <input type="date" name="starts_at" class="form-control-custom">
            </div>
            <div>
                <label class="form-label-custom">End Date</label>
                <input type="date" name="ends_at" class="form-control-custom">
            </div>
        </div>
        <button type="submit" class="btn-primary-custom">
            <i class="bi bi-plus-circle"></i> Create Ad
        </button>
    </form>
</div>

<div class="content-card">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:1rem;">All Ads ({{ count($ads) }})</p>

    @if(count($ads) > 0)
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
            <thead>
                <tr style="background:#f5f5f5;">
                    <th style="padding:0.6rem;text-align:left;border-bottom:2px solid #eee;">Image</th>
                    <th style="padding:0.6rem;text-align:left;border-bottom:2px solid #eee;">Title</th>
                    <th style="padding:0.6rem;text-align:left;border-bottom:2px solid #eee;">Description</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Placement</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Period</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Status</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ads as $ad)
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:0.6rem;">
                        @if($ad->image)
                        <img src="{{ asset($ad->image) }}" alt="{{ $ad->title }}" style="width:60px;height:40px;object-fit:cover;border-radius:6px;">
                        @else
                        <span style="color:#ccc;font-size:0.75rem;">No image</span>
                        @endif
                    </td>
                    <td style="padding:0.6rem;font-weight:600;color:#F4845F;">{{ $ad->title }}</td>
                    <td style="padding:0.6rem;color:#666;font-size:0.75rem;">{{ $ad->description ?? '—' }}</td>
                    <td style="padding:0.6rem;text-align:center;">
                        <span style="background:#F4845F;color:white;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.7rem;font-weight:600;">
                            {{ ucfirst($ad->placement) }}
                        </span>
                    </td>
                    <td style="padding:0.6rem;text-align:center;font-size:0.72rem;">
                        @if($ad->starts_at || $ad->ends_at)
                        {{ $ad->starts_at ? $ad->starts_at->format('M d') : 'Start' }}
                        —
                        {{ $ad->ends_at ? $ad->ends_at->format('M d, Y') : 'No end' }}
                        @if($ad->ends_at && $ad->ends_at->isPast())
                        <span style="background:#C0392B;color:white;padding:0.1rem 0.4rem;border-radius:10px;font-size:0.65rem;font-weight:600;margin-left:0.2rem;">Ended</span>
                        @endif
                        @else
                        Always
                        @endif
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        <form action="{{ route('admin.ads.toggle', $ad->id) }}" method="POST" style="display:inline;">
                            @csrf @method('PUT')
                            <button type="submit"
                                style="background:{{ $ad->is_active ? '#4CAF50' : '#ccc' }};color:white;border:none;border-radius:20px;padding:0.2rem 0.8rem;font-size:0.72rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
                                {{ $ad->is_active ? '✓ On' : '✗ Off' }}
                            </button>
                        </form>
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        <form action="{{ route('admin.ads.delete', $ad->id) }}" method="POST" style="display:inline;" onsubmit="return confirm('Delete ad {{ $ad->title }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:#C0392B;color:white;border:none;border-radius:6px;padding:0.3rem 0.6rem;font-size:0.75rem;cursor:pointer;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div style="text-align:center;padding:2rem;color:#aaa;font-size:0.85rem;">
        <i class="bi bi-megaphone" style="font-size:2.5rem;display:block;margin-bottom:0.5rem;color:#ddd;"></i>
        No ads yet. Create one above!
    </div>
    @endif
</div>

@endsection
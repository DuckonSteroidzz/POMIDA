@extends('admin.layout')

@section('title', 'Ads - Peachy Admin')

@section('content')

<style>
    .pchy-modal-overlay{display:none;position:fixed;inset:0;background:rgba(74,59,54,.5);z-index:1000;align-items:center;justify-content:center;padding:1rem}
    .pchy-modal-overlay.show{display:flex}
    .pchy-modal-box{background:#fff;border-radius:18px;padding:1.3rem;width:100%;max-width:560px;max-height:calc(100vh - 2rem);overflow-y:auto;box-shadow:0 20px 50px -20px rgba(139,26,26,.5)}
    .pchy-modal-hd{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1rem;padding-bottom:.85rem;border-bottom:2px dashed rgba(244,132,95,.3)}
    .pchy-modal-title{font-family:'Poppins',sans-serif;font-size:1.05rem;font-weight:700;color:#8B1A1A;margin:0;display:flex;align-items:center;gap:.5rem;}
    .pchy-modal-close{border:0;background:none;color:#9a837b;font-size:1.3rem;line-height:1;cursor:pointer;padding:0}
    .pchy-modal-close:hover{color:#C0392B}
    .pchy-modal-preview-wrap{display:flex;flex-direction:column;align-items:center;gap:.5rem;margin-bottom:1.1rem;padding-bottom:1rem;border-bottom:1px dashed rgba(244,132,95,.3)}
    .pchy-modal-preview{width:180px;height:110px;object-fit:cover;border-radius:12px;border:1.5px solid #F6B49B;box-shadow:0 8px 20px -14px rgba(139,26,26,.4);display:block}
    .pchy-modal-preview-ph{width:180px;height:110px;border-radius:12px;background:linear-gradient(135deg,#fdf1e6,#F8D7B0);color:#F4845F;display:grid;place-items:center;font-size:1.6rem}
    .pchy-modal-foot{display:flex;gap:.6rem;margin-top:.9rem}
    .pchy-modal-foot .btn-primary-custom{flex:1;text-align:center;}
    .pchy-modal-foot .btn-ghost{flex:1;background:#fff;color:#C0392B;border:1.5px solid #F6B49B;border-radius:8px;padding:.6rem 1rem;font-size:.85rem;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;}
    .pchy-modal-foot .btn-ghost:hover{background:#fff4ec}
</style>

<p class="page-title">Ads Management</p>

<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:1rem;">Create New Ad</p>
    <form action="{{ route('admin.ads.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.75rem;margin-bottom:0.75rem;">
            <div>
                <label class="form-label-custom">Title *</label>
                <input type="text" name="title" class="form-control-custom" required autocomplete="off">
            </div>
            <div>
                <label class="form-label-custom">Description</label>
                <input type="text" name="description" class="form-control-custom" autocomplete="off">
            </div>
            <div>
                <label class="form-label-custom">Image</label>
                <input type="file" name="image" class="form-control-custom" accept="image/*">
            </div>
            <div>
                <label class="form-label-custom">Link (optional)</label>
                <input type="url" name="link" class="form-control-custom" autocomplete="off">
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
                        <img src="{{ \App\Support\Img::url($ad->image) }}" alt="{{ $ad->title }}" style="width:60px;height:40px;object-fit:cover;border-radius:6px;">
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
                    <td style="padding:0.6rem;text-align:center;white-space:nowrap;">
                        <button type="button"
                            class="btn-edit-custom"
                            title="Edit ad"
                            data-id="{{ $ad->id }}"
                            data-title="{{ $ad->title }}"
                            data-description="{{ $ad->description }}"
                            data-image="{{ $ad->image ? \App\Support\Img::url($ad->image) : '' }}"
                            data-link="{{ $ad->link }}"
                            data-placement="{{ $ad->placement }}"
                            data-starts-at="{{ $ad->starts_at ? $ad->starts_at->format('Y-m-d') : '' }}"
                            data-ends-at="{{ $ad->ends_at ? $ad->ends_at->format('Y-m-d') : '' }}"
                            onclick="openEditAdModal(this)"
                            style="margin-right:0.35rem;padding:0.3rem 0.6rem;font-size:0.75rem;">
                            <i class="bi bi-pencil-square"></i>
                        </button>
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

{{-- Edit Ad Modal --}}
<div class="pchy-modal-overlay" id="editAdModal">
    <div class="pchy-modal-box">
        <div class="pchy-modal-hd">
            <p class="pchy-modal-title"><i class="bi bi-pencil-square"></i> Edit Ad</p>
            <button type="button" class="pchy-modal-close" onclick="closeEditAdModal()">&times;</button>
        </div>

        <form id="editAdForm" method="POST" enctype="multipart/form-data" autocomplete="off">
            @csrf
            @method('PUT')

            <div class="pchy-modal-preview-wrap">
                <img id="editAdPreview" class="pchy-modal-preview" src="" alt="" style="display:none;">
                <div id="editAdPreviewPh" class="pchy-modal-preview-ph"><i class="bi bi-image"></i></div>
                <span class="form-label-custom" style="margin:0;">Current Image</span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.75rem;margin-bottom:0.75rem;">
                <div>
                    <label class="form-label-custom">Title *</label>
                    <input type="text" name="title" id="editAdTitle" class="form-control-custom" required autocomplete="off">
                </div>
                <div>
                    <label class="form-label-custom">Description</label>
                    <input type="text" name="description" id="editAdDescription" class="form-control-custom" autocomplete="off">
                </div>
                <div>
                    <label class="form-label-custom">Replace Image (optional)</label>
                    <input type="file" name="image" class="form-control-custom" accept="image/*">
                </div>
                <div>
                    <label class="form-label-custom">Link (optional)</label>
                    <input type="url" name="link" id="editAdLink" class="form-control-custom" autocomplete="off">
                </div>
                <div>
                    <label class="form-label-custom">Placement *</label>
                    <select name="placement" id="editAdPlacement" class="form-control-custom" required>
                        <option value="game">Game Page</option>
                        <option value="menu">Menu Page</option>
                        <option value="cart">Cart Page</option>
                        <option value="orders">Orders Page</option>
                    </select>
                </div>
                <div>
                    <label class="form-label-custom">Start Date</label>
                    <input type="date" name="starts_at" id="editAdStartsAt" class="form-control-custom">
                </div>
                <div>
                    <label class="form-label-custom">End Date</label>
                    <input type="date" name="ends_at" id="editAdEndsAt" class="form-control-custom">
                </div>
            </div>

            <div class="pchy-modal-foot">
                <button type="button" class="btn-ghost" onclick="closeEditAdModal()">Cancel</button>
                <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditAdModal(btn) {
        document.getElementById('editAdForm').action = '{{ url('admin/ads') }}/' + btn.dataset.id;
        document.getElementById('editAdTitle').value = btn.dataset.title || '';
        document.getElementById('editAdDescription').value = btn.dataset.description || '';
        document.getElementById('editAdLink').value = btn.dataset.link || '';
        document.getElementById('editAdPlacement').value = btn.dataset.placement || 'game';
        document.getElementById('editAdStartsAt').value = btn.dataset.startsAt || '';
        document.getElementById('editAdEndsAt').value = btn.dataset.endsAt || '';

        var preview = document.getElementById('editAdPreview');
        var placeholder = document.getElementById('editAdPreviewPh');
        if (btn.dataset.image) {
            preview.src = btn.dataset.image;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
        } else {
            preview.style.display = 'none';
            placeholder.style.display = 'grid';
        }

        document.getElementById('editAdModal').classList.add('show');
    }

    function closeEditAdModal() {
        document.getElementById('editAdModal').classList.remove('show');
    }
</script>

@endsection
@extends('admin.layout')

@section('title', 'Vouchers - Peachy Admin')

@section('content')

<p class="page-title">Vouchers & Game</p>

@if(session('success'))
<div class="alert-success-custom">
    <i class="bi bi-check-circle"></i> {{ session('success') }}
</div>
@endif

@php
$gameEnabled = \Illuminate\Support\Facades\DB::table('settings')->where('key', 'game_enabled')->value('value');
@endphp
<div class="content-card" style="margin-bottom:1rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <p style="font-size:0.9rem;font-weight:700;color:#333;margin:0;">🎰 Spin Wheel Game</p>
            <p style="font-size:0.78rem;color:#888;margin:0;">Enable or disable the game for customers</p>
        </div>
        <form action="{{ route('admin.game.toggle') }}" method="POST" style="margin:0;">
            @csrf
            <button type="submit"
                style="background:{{ $gameEnabled === '1' ? '#4CAF50' : '#ccc' }};color:white;border:none;border-radius:20px;padding:0.4rem 1.2rem;font-size:0.78rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
                {{ $gameEnabled === '1' ? '✓ Enabled' : '✗ Disabled' }}
            </button>
        </form>
    </div>
</div>

<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:1rem;">Create New Voucher</p>
    <form action="{{ route('admin.vouchers.store') }}" method="POST" autocomplete="off">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.75rem;margin-bottom:0.75rem;">
            <div>
                <label class="form-label-custom">Voucher Code *</label>
                <input type="text" name="code" class="form-control-custom" required style="text-transform:uppercase;">
                @error('code')<span style="color:red;font-size:0.75rem;">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="form-label-custom">Description</label>
                <input type="text" name="description" class="form-control-custom">
            </div>
            <div>
                <label class="form-label-custom">Discount Type *</label>
                <select name="discount_type" class="form-control-custom" required>
                    <option value="fixed">Fixed (₱)</option>
                    <option value="percent">Percent (%)</option>
                </select>
            </div>
            <div>
                <label class="form-label-custom">Discount Value *</label>
                <input type="number" name="discount_value" class="form-control-custom">
            </div>
            <div>
                <label class="form-label-custom">Max Uses *</label>
                <input type="number" name="max_uses" class="form-control-custom" placeholder="e.g. 100" min="1" value="100" required>
            </div>
            <div>
                <label class="form-label-custom">Minimum Order (₱)</label>
                <input type="number" name="minimum_order" class="form-control-custom" placeholder="e.g. 200" min="0" step="0.01" value="0">
            </div>
            <div>
                <label class="form-label-custom">Valid From (Start Date)</label>
                <input type="date" name="valid_from" class="form-control-custom">
                <small style="font-size:0.7rem;color:#aaa;">Date when voucher can be used</small>
            </div>
            <div>
                <label class="form-label-custom">Expiry Date</label>
                <input type="date" name="expires_at" class="form-control-custom">
            </div>
            <div>
                <label class="form-label-custom">Points Required</label>
                <input type="number" name="points_required" class="form-control-custom" placeholder="e.g. 50" min="0" value="0">
                <small style="font-size:0.7rem;color:#aaa;">0 = direct voucher (no points needed)</small>
            </div>
        </div>
        <button type="submit" class="btn-primary-custom">
            <i class="bi bi-plus-circle"></i> Create Voucher
        </button>
    </form>
</div>

<div class="content-card">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:1rem;">
        All Vouchers ({{ count($vouchers) }})
        <span style="font-size:0.75rem;color:#888;font-weight:400;margin-left:0.5rem;">Active vouchers appear on the spin wheel</span>
    </p>

    @if(count($vouchers) > 0)
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
            <thead>
                <tr style="background:#f5f5f5;">
                    <th style="padding:0.6rem;text-align:left;border-bottom:2px solid #eee;">Code</th>
                    <th style="padding:0.6rem;text-align:left;border-bottom:2px solid #eee;">Description</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Discount</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Uses</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Min Order</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Valid From</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Expiry</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Points Req.</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Wheel</th>
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($vouchers as $voucher)
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:0.6rem;font-weight:700;color:#F4845F;">{{ $voucher->code }}</td>
                    <td style="padding:0.6rem;color:#666;">{{ $voucher->description ?? '—' }}</td>
                    <td style="padding:0.6rem;text-align:center;">
                        @if($voucher->discount_type === 'fixed')
                        ₱{{ number_format($voucher->discount_value, 2) }}
                        @else
                        {{ $voucher->discount_value }}%
                        @endif
                    </td>
                    <td style="padding:0.6rem;text-align:center;">{{ $voucher->used_count }}/{{ $voucher->max_uses }}</td>
                    <td style="padding:0.6rem;text-align:center;">₱{{ number_format($voucher->minimum_order, 2) }}</td>
                    <td style="padding:0.6rem;text-align:center;">
                        @if($voucher->valid_from)
                        {{ \Carbon\Carbon::parse($voucher->valid_from)->format('M d, Y') }}
                        @else
                        <span style="color:#aaa;">—</span>
                        @endif
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        @if($voucher->expires_at)
                        {{ $voucher->expires_at->format('M d, Y') }}
                        @if($voucher->expires_at->isPast())
                        <span style="background:#C0392B;color:white;padding:0.1rem 0.4rem;border-radius:10px;font-size:0.65rem;font-weight:600;margin-left:0.3rem;">Expired</span>
                        @endif
                        @else
                        No expiry
                        @endif
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        {{ $voucher->points_required > 0 ? $voucher->points_required . ' pts' : 'Direct' }}
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        <form action="{{ route('admin.vouchers.toggle', $voucher->id) }}" method="POST" style="display:inline;">
                            @csrf @method('PUT')
                            <button type="submit"
                                style="background:{{ $voucher->is_active ? '#4CAF50' : '#ccc' }};color:white;border:none;border-radius:20px;padding:0.2rem 0.8rem;font-size:0.72rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
                                {{ $voucher->is_active ? '✓ On' : '✗ Off' }}
                            </button>
                        </form>
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        <form action="{{ route('admin.vouchers.delete', $voucher->id) }}" method="POST" style="display:inline;" onsubmit="return confirm('Delete voucher {{ $voucher->code }}?')">
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
        <i class="bi bi-ticket-perforated" style="font-size:2.5rem;display:block;margin-bottom:0.5rem;color:#ddd;"></i>
        No vouchers yet. Create one above!
    </div>
    @endif
</div>

@endsection
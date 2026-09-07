@extends('admin.layout')

@section('title', 'Vouchers - Peachy Admin')

@section('content')

@php
    // Staff reach this page read-only: they can see the All Vouchers table to
    // read active codes to customers, but not the Spin Wheel toggle, the
    // Create form, or the per-row Actions. Every mutating route is also behind
    // role:admin, so this is presentation only, not the security boundary.
    $isAdmin = \Illuminate\Support\Facades\Auth::guard('admin')->user()?->isAdmin() ?? false;
@endphp

<style>
    .pchy-modal-overlay{display:none;position:fixed;inset:0;background:rgba(74,59,54,.5);z-index:1000;align-items:center;justify-content:center;padding:1rem}
    .pchy-modal-overlay.show{display:flex}
    .pchy-modal-box{background:#fff;border-radius:18px;padding:1.3rem;width:100%;max-width:560px;max-height:calc(100vh - 2rem);overflow-y:auto;box-shadow:0 20px 50px -20px rgba(139,26,26,.5)}
    .pchy-modal-hd{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1rem;padding-bottom:.85rem;border-bottom:2px dashed rgba(244,132,95,.3)}
    .pchy-modal-title{font-family:'Poppins',sans-serif;font-size:1.05rem;font-weight:700;color:#8B1A1A;margin:0;display:flex;align-items:center;gap:.5rem;}
    .pchy-modal-close{border:0;background:none;color:#9a837b;font-size:1.3rem;line-height:1;cursor:pointer;padding:0}
    .pchy-modal-close:hover{color:#C0392B}
    .pchy-modal-foot{display:flex;gap:.6rem;margin-top:.9rem}
    .pchy-modal-foot .btn-primary-custom{flex:1;text-align:center;}
    .pchy-modal-foot .btn-ghost{flex:1;background:#fff;color:#C0392B;border:1.5px solid #F6B49B;border-radius:8px;padding:.6rem 1rem;font-size:.85rem;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;}
    .pchy-modal-foot .btn-ghost:hover{background:#fff4ec}
    .voucher-form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem 1.25rem;align-items:start;margin-bottom:1rem}
    @media (max-width:820px){.voucher-form-grid{grid-template-columns:repeat(2,1fr)}}
    @media (max-width:560px){.voucher-form-grid{grid-template-columns:1fr}}
</style>

<p class="page-title">{{ $isAdmin ? 'Vouchers & Game' : 'Vouchers' }}</p>

@unless($isAdmin)
<p style="font-size:0.82rem;color:#888;margin:-0.5rem 0 1rem;">
    Share an active voucher code below with a customer at the counter, or use the
    green <i class="bi bi-ticket-perforated"></i> button to issue a fresh single-use code.
</p>
@endunless

{{-- The code that was just issued — shown as a modal popup.

     Shown big, on its own, and with a Copy button, because this is the whole
     point of the action: the admin has to read it aloud or write it on a
     receipt. A code minted with no way to retrieve it would be worse than
     useless — the row would exist, count against the voucher's supply, and be
     unspendable by anyone.

     It is flashed once and not persisted anywhere in the page state, so a
     refresh does not leave a stranger's code sitting on a counter screen. --}}
@if(session('issued_claim_code'))
<div class="pchy-modal-overlay show" id="issuedCodeModal">
    <div class="pchy-modal-box" style="max-width:420px;text-align:center;">
        <div class="pchy-modal-hd" style="justify-content:center;">
            <p class="pchy-modal-title"><i class="bi bi-ticket-perforated"></i> New Code Generated</p>
            <button type="button" class="pchy-modal-close" onclick="document.getElementById('issuedCodeModal').classList.remove('show')">&times;</button>
        </div>

        <p style="font-size:0.75rem;color:#8A6A61;margin:0 0 0.15rem;">Voucher <strong>{{ session('issued_claim_voucher') }}</strong></p>

        <code id="issuedClaimCode"
            style="display:block;font-size:1.7rem;font-weight:800;letter-spacing:0.12em;color:#8B1A1A;background:#FFF7F3;border:2px dashed #F4845F;border-radius:10px;padding:0.7rem 0.5rem;margin:0.6rem 0;word-break:break-all;">{{ session('issued_claim_code') }}</code>

        <p style="font-size:0.74rem;color:#8A6A61;margin:0 0 0.9rem;line-height:1.45;">
            Give this 1-time code to customer or use for promos.
        </p>

        <button type="button" id="copyIssuedCodeBtn" class="btn-primary-custom" style="width:100%;">
            <i class="bi bi-clipboard"></i> Copy Code
        </button>
    </div>
</div>

<script>
    (function () {
        var btn = document.getElementById('copyIssuedCodeBtn');
        var el  = document.getElementById('issuedClaimCode');

        if (!btn || !el) {
            return;
        }

        btn.addEventListener('click', function () {
            var code = el.textContent.trim();

            var done = function () {
                btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
                // Auto-dismiss the modal shortly after copying — the admin has
                // the code now, and leaving a stranger's code on a counter
                // screen is exactly what this modal is not meant to do.
                setTimeout(function () {
                    var modal = document.getElementById('issuedCodeModal');
                    if (modal) {
                        modal.classList.remove('show');
                    }
                    btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy Code';
                }, 800);
            };

            // navigator.clipboard needs a secure context, which a counter
            // machine on plain http is not. Fall back rather than silently
            // doing nothing.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(code).then(done).catch(function () {
                    window.prompt('Copy this code:', code);
                });
                return;
            }

            window.prompt('Copy this code:', code);
        });
    })();
</script>
@endif

@if($isAdmin)
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
        {{-- Fixed 3-column grid. Rows read:
             Voucher Code | Description | Discount Type
             Discount Value | Max Uses | Minimum Order
             Valid From | Expiry Date | Points Required
             align-items:start keeps every field top-aligned so the helper
             notes under some inputs never push a whole row out of line. --}}
        <div class="voucher-form-grid">
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
                <input type="number" name="max_uses" class="form-control-custom" min="1" value="100" required>
            </div>
            <div>
                <label class="form-label-custom">Minimum Order (₱)</label>
                <input type="number" name="minimum_order" class="form-control-custom" min="0" step="0.01" value="0">
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
                <input type="number" name="points_required" class="form-control-custom" min="0" value="0">
                <small style="font-size:0.7rem;color:#aaa;">0 = direct voucher (no points needed)</small>
            </div>
        </div>
        <button type="submit" class="btn-primary-custom">
            <i class="bi bi-plus-circle"></i> Create Voucher
        </button>
    </form>
</div>
@endif

<div class="content-card">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:1rem;">
        All Vouchers ({{ count($vouchers) }})
        <span style="font-size:0.75rem;color:#888;font-weight:400;margin-left:0.5rem;">{{ $isAdmin ? 'Active vouchers appear on the spin wheel' : 'Share an active code with a customer' }}</span>
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
                    @if($isAdmin)
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Wheel</th>
                    @else
                    <th style="padding:0.6rem;text-align:center;border-bottom:2px solid #eee;">Status</th>
                    @endif
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
                    @if($isAdmin)
                    <td style="padding:0.6rem;text-align:center;">
                        <form action="{{ route('admin.vouchers.toggle', $voucher->id) }}" method="POST" style="display:inline;">
                            @csrf @method('PUT')
                            <button type="submit"
                                style="background:{{ $voucher->is_active ? '#4CAF50' : '#ccc' }};color:white;border:none;border-radius:20px;padding:0.2rem 0.8rem;font-size:0.72rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
                                {{ $voucher->is_active ? '✓ On' : '✗ Off' }}
                            </button>
                        </form>
                    </td>
                    @else
                    <td style="padding:0.6rem;text-align:center;">
                        <span style="background:{{ $voucher->is_active ? '#4CAF50' : '#ccc' }};color:white;border-radius:20px;padding:0.2rem 0.8rem;font-size:0.72rem;font-weight:600;">
                            {{ $voucher->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @endif
                    {{-- Actions column. Staff get ONLY the green "Issue Code"
                         button — reading a code to a walk-in customer is a
                         counter task. Edit and Delete stay admin-only. --}}
                    <td style="padding:0.6rem;text-align:center;white-space:nowrap;">
                        @if($isAdmin)
                        <button type="button"
                            class="btn-edit-custom"
                            title="Edit voucher"
                            data-id="{{ $voucher->id }}"
                            data-code="{{ $voucher->code }}"
                            data-description="{{ $voucher->description }}"
                            data-discount-type="{{ $voucher->discount_type }}"
                            data-discount-value="{{ $voucher->discount_value }}"
                            data-max-uses="{{ $voucher->max_uses }}"
                            data-minimum-order="{{ $voucher->minimum_order }}"
                            data-valid-from="{{ $voucher->valid_from ? \Carbon\Carbon::parse($voucher->valid_from)->format('Y-m-d') : '' }}"
                            data-expires-at="{{ $voucher->expires_at ? $voucher->expires_at->format('Y-m-d') : '' }}"
                            data-points-required="{{ $voucher->points_required }}"
                            data-is-active="{{ $voucher->is_active ? '1' : '0' }}"
                            onclick="openEditVoucherModal(this)"
                            style="margin-right:0.35rem;padding:0.3rem 0.6rem;font-size:0.75rem;">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        @endif
                        {{-- Issue a bearer code for a walk-in customer who has
                             no account and never played the wheel. Disabled,
                             with the reason as a tooltip, whenever the voucher
                             is not currently issuable — the server refuses it
                             either way (Voucher::issuanceErrorFor), this just
                             saves the admin a pointless round trip. --}}
                        @php $issueBlocked = $voucher->issuanceErrorFor(); @endphp
                        @if($issueBlocked === null)
                        <form action="{{ route('admin.vouchers.issue-code', $voucher->id) }}" method="POST" style="display:inline;"
                            onsubmit="return confirm('Issue a new code for {{ $voucher->code }}? It counts against this voucher\'s usage limit.')">
                            @csrf
                            <button type="submit" title="Issue a code for a walk-in customer"
                                style="background:#4CAF50;color:white;border:none;border-radius:6px;padding:0.3rem 0.6rem;font-size:0.75rem;cursor:pointer;margin-right:0.35rem;">
                                <i class="bi bi-ticket-perforated"></i>
                            </button>
                        </form>
                        @else
                        <button type="button" disabled title="{{ $issueBlocked }}"
                            style="background:#e0e0e0;color:#999;border:none;border-radius:6px;padding:0.3rem 0.6rem;font-size:0.75rem;cursor:not-allowed;margin-right:0.35rem;">
                            <i class="bi bi-ticket-perforated"></i>
                        </button>
                        @endif

                        @if($isAdmin)
                        <form action="{{ route('admin.vouchers.delete', $voucher->id) }}" method="POST" style="display:inline;" onsubmit="return confirm('Delete voucher {{ $voucher->code }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:#C0392B;color:white;border:none;border-radius:6px;padding:0.3rem 0.6rem;font-size:0.75rem;cursor:pointer;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div style="text-align:center;padding:2rem;color:#aaa;font-size:0.85rem;">
        <i class="bi bi-ticket-perforated" style="font-size:2.5rem;display:block;margin-bottom:0.5rem;color:#ddd;"></i>
        No vouchers yet.@if($isAdmin) Create one above!@endif
    </div>
    @endif
</div>

@if($isAdmin)
{{-- Edit Voucher Modal --}}
<div class="pchy-modal-overlay" id="editVoucherModal">
    <div class="pchy-modal-box">
        <div class="pchy-modal-hd">
            <p class="pchy-modal-title"><i class="bi bi-pencil-square"></i> Edit Voucher</p>
            <button type="button" class="pchy-modal-close" onclick="closeEditVoucherModal()">&times;</button>
        </div>

        <form id="editVoucherForm" method="POST">
            @csrf
            @method('PUT')

            <div class="voucher-form-grid">
                <div>
                    <label class="form-label-custom">Voucher Code *</label>
                    <input type="text" name="code" id="editVoucherCode" class="form-control-custom" required style="text-transform:uppercase;">
                </div>
                <div>
                    <label class="form-label-custom">Description</label>
                    <input type="text" name="description" id="editVoucherDescription" class="form-control-custom">
                </div>
                <div>
                    <label class="form-label-custom">Discount Type *</label>
                    <select name="discount_type" id="editVoucherDiscountType" class="form-control-custom" required>
                        <option value="fixed">Fixed (₱)</option>
                        <option value="percent">Percent (%)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label-custom">Discount Value *</label>
                    <input type="number" name="discount_value" id="editVoucherDiscountValue" class="form-control-custom" min="1" required>
                </div>
                <div>
                    <label class="form-label-custom">Max Uses *</label>
                    <input type="number" name="max_uses" id="editVoucherMaxUses" class="form-control-custom" min="1" required>
                </div>
                <div>
                    <label class="form-label-custom">Minimum Order (₱)</label>
                    <input type="number" name="minimum_order" id="editVoucherMinimumOrder" class="form-control-custom" min="0" step="0.01">
                </div>
                <div>
                    <label class="form-label-custom">Valid From (Start Date)</label>
                    <input type="date" name="valid_from" id="editVoucherValidFrom" class="form-control-custom">
                </div>
                <div>
                    <label class="form-label-custom">Expiry Date</label>
                    <input type="date" name="expires_at" id="editVoucherExpiresAt" class="form-control-custom">
                </div>
                <div>
                    <label class="form-label-custom">Points Required</label>
                    <input type="number" name="points_required" id="editVoucherPointsRequired" class="form-control-custom" min="0">
                    <small style="font-size:0.7rem;color:#aaa;">0 = direct voucher (no points needed)</small>
                </div>
                <div>
                    <label class="form-label-custom">Wheel</label>
                    <div style="display:flex;align-items:center;gap:0.5rem;padding-top:0.4rem;">
                        <input type="checkbox" name="is_active" id="editVoucherIsActive" value="1" style="width:16px;height:16px;">
                        <label for="editVoucherIsActive" style="font-size:0.82rem;color:#555;margin:0;">Active on spin wheel</label>
                    </div>
                </div>
            </div>

            <div class="pchy-modal-foot">
                <button type="button" class="btn-ghost" onclick="closeEditVoucherModal()">Cancel</button>
                <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditVoucherModal(btn) {
        document.getElementById('editVoucherForm').action = '{{ url('admin/vouchers') }}/' + btn.dataset.id;
        document.getElementById('editVoucherCode').value = btn.dataset.code || '';
        document.getElementById('editVoucherDescription').value = btn.dataset.description || '';
        document.getElementById('editVoucherDiscountType').value = btn.dataset.discountType || 'fixed';
        document.getElementById('editVoucherDiscountValue').value = btn.dataset.discountValue || '';
        document.getElementById('editVoucherMaxUses').value = btn.dataset.maxUses || '';
        document.getElementById('editVoucherMinimumOrder').value = btn.dataset.minimumOrder || '0';
        document.getElementById('editVoucherValidFrom').value = btn.dataset.validFrom || '';
        document.getElementById('editVoucherExpiresAt').value = btn.dataset.expiresAt || '';
        document.getElementById('editVoucherPointsRequired').value = btn.dataset.pointsRequired || '0';
        document.getElementById('editVoucherIsActive').checked = btn.dataset.isActive === '1';
        document.getElementById('editVoucherModal').classList.add('show');
    }

    function closeEditVoucherModal() {
        document.getElementById('editVoucherModal').classList.remove('show');
    }
</script>
@endif

@endsection
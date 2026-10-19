@extends('admin.layout')

@section('title', 'Vouchers - Peachy Admin')

@section('content')

@php
    /*
     * Three tiers on this page.
     *
     *  staff — "View Vouchers" is Y | Y | Y, so they still read the All
     *      Vouchers table to give active codes to customers at the counter,
     *      plus the green "Issue Code" button (a counter task, shared with
     *      them since 2026-09-04). No authoring, no toggling, no deleting.
     *
     *  $canAuthorVouchers (owner + supervisor) — "Create Vouchers", "Edit
     *      Vouchers" and "Activate/Deactivate Vouchers" are Y | LIMITED | N.
     *      This is the Create form, the Edit button and the on/off toggle —
     *      but for a supervisor the last two are decided PER VOUCHER by
     *      $canManageVoucher below, not by this flag alone.
     *
     *  $isAdmin (owner alone) — two things. "Delete Vouchers" is Y | N | N:
     *      destroying a voucher cascades to every customer's unused claim on
     *      it, which is irreversible and not a per-branch act. And the Spin
     *      Wheel switch, which is System-Level Configuration (Y | N | N)
     *      rather than a voucher action at all.
     *
     * THE BRANCH SCOPE (Sept 2026)
     * ---------------------------
     * vouchers.branch_id is nullable and NULL means GLOBAL — valid at every
     * branch — exactly as menu_items.branch_id does. A supervisor's listing is
     * already narrowed server-side by showVouchers() to their own branch plus
     * the global ones; the globals are shown READ-ONLY so a branch manager
     * still knows what company-wide promotions are running (their own staff can
     * read them on this very page) without being able to touch one.
     *
     * Presentation only — every mutating route is gated by its own role group
     * AND by AdminController::promotionScopeRefusal(), and those are the
     * security boundary, not this. The per-row $canManageVoucher mirrors that
     * method exactly (see admin/partials/voucher-list-results); it is the
     * button agreeing with the server, never a substitute.
     */
    $adminUser = \Illuminate\Support\Facades\Auth::guard('admin')->user();
    $isAdmin = $adminUser?->isAdmin() ?? false;
    $canAuthorVouchers = $adminUser?->isManager() ?? false;
    $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();
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

    /* ── Pagination — same visual language as admin/completed-orders.blade.php's
         co-pager, copied rather than shared since this page carries its own
         inline styles rather than a stylesheet include. ── */
    .voucher-pager {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
        gap: 0.7rem; padding: 0.8rem 1rem; margin-top: 0.5rem;
        border-top: 1px solid #F0E2D5; background: #FFFBF7; border-radius: 0 0 16px 16px;
    }
    .voucher-pager-info { font-size: 0.76rem; color: #8B7A72; font-weight: 600; }
    .voucher-pager-nav { display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; }
    .voucher-page-btn {
        min-width: 32px; height: 32px; padding: 0 0.5rem;
        background: #fff; border: 1px solid #F0E2D5; border-radius: 9px;
        font-family: 'Poppins', sans-serif; font-size: 0.76rem; font-weight: 700; color: #5B4740;
        cursor: pointer; text-decoration: none;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .voucher-page-btn:hover:not(.is-disabled) { border-color: #F4845F; color: #C0392B; }
    .voucher-page-btn.is-disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
    .voucher-page-btn.is-active {
        background: linear-gradient(135deg, #F4845F, #EF8585);
        color: #fff; border-color: transparent;
    }
    .voucher-page-gap { color: #C4B6AE; padding: 0 0.15rem; }

    /* ── Promotions & Rewards > Vouchers (Oct 2026) ── */
    .vch-section-head { margin: 0 0 0.9rem; }
    .vch-section-title { font-family: 'Fraunces', Georgia, serif; font-size: 1.15rem; font-weight: 700; color: #8B1A1A; margin: 0; }
    .vch-section-sub { font-size: 0.82rem; color: #8A6A61; margin: 0.1rem 0 0; }

    .vch-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.85rem; margin-bottom: 1rem; }
    @media (max-width: 900px) { .vch-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .vch-stat { display: flex; align-items: center; gap: 0.75rem; min-width: 0; background: #fff; border: 1px solid #F0E2D5; border-radius: 16px; padding: 0.9rem 1rem; }
    .vch-stat-icon { display: grid; place-items: center; flex: 0 0 auto; width: 40px; height: 40px; border-radius: 12px; font-size: 1.1rem; }
    .vch-stat-icon.is-total { background: #FDE8DE; color: #C0392B; }
    .vch-stat-icon.is-active { background: #E4F2EA; color: #2E7D5B; }
    .vch-stat-icon.is-wheel { background: #FFF1D0; color: #B36B00; }
    .vch-stat-icon.is-expired { background: #F1ECE9; color: #7B645C; }
    .vch-stat-num { font-family: 'Fraunces', Georgia, serif; font-size: 1.45rem; font-weight: 700; line-height: 1.1; color: #5A2920; margin: 0; }
    .vch-stat-label { font-size: 0.74rem; color: #8A6A61; margin: 0.1rem 0 0; }

    .vch-card { background: #fff; border: 1px solid #F0E2D5; border-radius: 16px; padding: 1.1rem 1.15rem; margin-bottom: 1rem; min-width: 0; }
    .vch-card-head { margin-bottom: 0.9rem; }
    .vch-card-title { font-size: 0.95rem; font-weight: 700; color: #333; margin: 0; }
    .vch-card-sub { font-size: 0.76rem; color: #8A6A61; margin: 0.1rem 0 0; }
    .vch-count { display: inline-block; margin-left: 0.35rem; background: #FDE8DE; color: #C0392B; border-radius: 999px; padding: 0.05rem 0.55rem; font-size: 0.72rem; font-weight: 700; vertical-align: middle; }

    .vch-wheelbar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
    .vch-wheelbar-text { display: flex; align-items: center; gap: 0.7rem; min-width: 0; }
    .vch-wheelbar-icon { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; background: #FFF1D0; color: #B36B00; font-size: 1.1rem; flex: 0 0 auto; }

    .vch-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem 1.1rem; align-items: start; }
    .vch-grid > div { min-width: 0; }
    .vch-wide { grid-column: span 2; }
    @media (max-width: 900px) { .vch-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 560px) { .vch-grid { grid-template-columns: minmax(0, 1fr); } .vch-wide { grid-column: auto; } }
    .vch-hint { display: block; font-size: 0.7rem; color: #9a837b; margin-top: 0.2rem; }
    .vch-actions { display: flex; justify-content: flex-end; align-items: center; gap: 0.6rem; margin-top: 1.1rem; }
    .vch-btn-ghost { background: #fff; color: #C0392B; border: 1.5px solid #F6B49B; border-radius: 8px; padding: 0.6rem 1.1rem; font-size: 0.85rem; font-weight: 700; cursor: pointer; font-family: 'Poppins', sans-serif; }
    .vch-btn-ghost:hover { background: #fff4ec; }

    .vch-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; margin-bottom: 0.9rem; }
    .vch-search { display: flex; align-items: center; gap: 0.5rem; flex: 1 1 240px; min-width: 0; margin: 0; padding: 0 0.8rem; min-height: 40px; background: #fff; border: 1.5px solid #F0E2D5; border-radius: 10px; color: #9a837b; }
    .vch-search:focus-within { border-color: #F4845F; }
    .vch-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-size: 0.85rem; color: #333; font-family: inherit; }
    .vch-filter { flex: 0 1 170px; min-width: 0; width: auto; margin: 0; }
    .vch-clear-filters { font-size: 0.8rem; font-weight: 600; color: #C0392B; text-decoration: none; }
    .vch-clear-filters:hover { text-decoration: underline; }
    .vch-clear-filters[hidden] { display: none; }
    /* Background refresh: the old list stays put, dimmed, until the new one is
       swapped in. Clicks are ignored meanwhile so a stale row is never acted on. */
    #vchResults { transition: opacity 0.15s ease; }
    #vchResults.is-loading { opacity: 0.5; pointer-events: none; }
    .vch-error { margin: 0 0 0.8rem; padding: 0.55rem 0.8rem; border-radius: 10px; background: #FDE2DC; color: #8B1A1A; font-size: 0.8rem; font-weight: 600; }
    .vch-error[hidden] { display: none; }
    .vch-error-retry { margin-left: 0.4rem; border: 0; background: none; padding: 0; color: #C0392B; font: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }
    @media (max-width: 560px) { .vch-filter { flex: 1 1 100%; } }

    /* The table scrolls inside its own wrapper; the page never does. */
    .vch-table-wrap { overflow-x: auto; max-width: 100%; border: 1px solid #F0E2D5; border-radius: 12px; }
    .vch-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 0.82rem; }
    .vch-table th { background: #FBF6F1; color: #7B645C; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; text-align: left; padding: 0.6rem 0.55rem; border-bottom: 1px solid #F0E2D5; white-space: nowrap; }
    .vch-table td { padding: 0.65rem 0.55rem; border-bottom: 1px solid #F6EEE7; vertical-align: middle; color: #5B4740; }
    .vch-table tr:last-child td { border-bottom: 0; }
    .vch-table .is-center { text-align: center; }
    .vch-table td > span { display: block; }
    .vch-cell-voucher { min-width: 140px; }
    .vch-code { font-weight: 700; color: #C0392B; letter-spacing: 0.02em; }
    .vch-desc { font-size: 0.75rem; color: #8A6A61; max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .vch-strong { font-weight: 700; color: #333; }
    .vch-muted { font-size: 0.72rem; color: #9a837b; }
    .vch-cell-usage { min-width: 95px; }
    .vch-track { display: block; height: 6px; margin-top: 0.3rem; border-radius: 999px; background: #F1E7DE; overflow: hidden; }
    .vch-track-fill { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #F4845F, #E86B5A); }
    .vch-track-fill.is-full { background: #8B7A72; }
    .vch-cell-valid { min-width: 130px; }
    .vch-left { font-size: 0.72rem; font-weight: 600; color: #2E7D5B; }
    .vch-left.is-soon { color: #B36B00; }
    .vch-left.is-expired { color: #C0392B; }
    .vch-badge { display: inline-block !important; padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; white-space: nowrap; }
    .vch-badge.is-global { background: #E4F2EA; color: #2E7D5B; }
    .vch-badge.is-branch { background: #FDE8DE; color: #C0392B; }
    .vch-status { display: inline-block !important; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; margin-right: 0.35rem; vertical-align: middle; }
    .vch-status.is-active { background: #E4F2EA; color: #2E7D5B; }
    .vch-status.is-used-up { background: #FFF1D0; color: #B36B00; }
    .vch-status.is-expired { background: #FDE2DC; color: #C0392B; }
    .vch-status.is-inactive { background: #EFEBE8; color: #7B645C; }
    .vch-cell-status { white-space: nowrap; }

    /* Switches. .vch-switch only reports (Wheel); .vch-toggle is the real
       on/off button (Status). */
    .vch-switch { display: inline-block !important; position: relative; width: 34px; height: 20px; border-radius: 999px; background: #D9CFC8; vertical-align: middle; }
    .vch-switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform 0.15s ease; }
    .vch-switch.is-on { background: #F4845F; }
    .vch-switch.is-on::after { transform: translateX(14px); }
    .vch-toggle { position: relative; width: 34px; height: 20px; padding: 0; border: 0; border-radius: 999px; background: #D9CFC8; cursor: pointer; vertical-align: middle; transition: background 0.15s ease; }
    .vch-toggle:focus-visible { outline: 3px solid rgba(244, 132, 95, 0.5); outline-offset: 2px; }
    .vch-toggle.is-on { background: #4CAF50; }
    .vch-toggle-knob { position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform 0.15s ease; }
    .vch-toggle.is-on .vch-toggle-knob { transform: translateX(14px); }

    .vch-kebab { width: 34px; height: 34px; border: 0; border-radius: 8px; background: transparent; color: #7B645C; font-size: 1.1rem; cursor: pointer; }
    .vch-kebab:hover, .vch-kebab[aria-expanded="true"] { background: #FDE8DE; color: #C0392B; }
    .vch-kebab:focus-visible { outline: 3px solid rgba(244, 132, 95, 0.5); outline-offset: 1px; }
    .vch-menu { position: fixed; z-index: 1100; min-width: 168px; padding: 0.3rem; text-align: left; background: #fff; border: 1px solid #F0E2D5; border-radius: 12px; box-shadow: 0 14px 30px -12px rgba(90, 41, 32, 0.35); }
    .vch-menu[hidden] { display: none; }
    .vch-menu form { margin: 0; }
    .vch-menu-item { display: flex; align-items: center; gap: 0.55rem; width: 100%; min-height: 38px; padding: 0 0.7rem; border: 0; border-radius: 8px; background: transparent; color: #5B4740; font-family: 'Poppins', sans-serif; font-size: 0.8rem; font-weight: 600; text-align: left; cursor: pointer; }
    .vch-menu-item:hover, .vch-menu-item:focus-visible { background: #FFF4EC; color: #C0392B; outline: none; }
    .vch-menu-item.is-issue { color: #2E7D5B; }
    .vch-menu-item.is-issue:hover, .vch-menu-item.is-issue:focus-visible { background: #E4F2EA; color: #2E7D5B; }
    .vch-menu-item.is-danger { color: #C0392B; }
    .vch-menu-item.is-disabled, .vch-menu-item:disabled { color: #b5a79f; cursor: not-allowed; background: transparent; }

    .vch-empty { text-align: center; padding: 2rem 1rem; color: #aaa; font-size: 0.85rem; }
    .vch-empty i { font-size: 2.5rem; display: block; margin-bottom: 0.5rem; color: #ddd; }
    .vch-empty a { color: #C0392B; font-weight: 600; }
</style>

@include('admin.partials.promotions-header', ['active' => 'vouchers'])

<div class="vch-section-head">
    <h2 class="vch-section-title">Vouchers</h2>
    <p class="vch-section-sub">Create, issue and manage voucher promotions.</p>
</div>

@unless($canAuthorVouchers)
<p style="font-size:0.82rem;color:#888;margin:0 0 1rem;">
    Share an active voucher code below with a customer at the counter, or use the
    <i class="bi bi-three-dots-vertical"></i> menu to issue a fresh single-use code.
</p>
@endunless

{{-- Four counts over EVERYTHING this viewer may see (a supervisor: their own
     branch plus the global vouchers), whatever the list's filters say. Each is
     the same word the list's Status filter and badges use: see
     Voucher::listStatus() / isOnWheel(). --}}
<div class="vch-stats" data-testid="voucher-stats">
    <div class="vch-stat">
        <span class="vch-stat-icon is-total"><i class="bi bi-ticket-perforated" aria-hidden="true"></i></span>
        <div><p class="vch-stat-num" data-stat="total">{{ number_format($voucherStats['total']) }}</p><p class="vch-stat-label">Total vouchers</p></div>
    </div>
    <div class="vch-stat">
        <span class="vch-stat-icon is-active"><i class="bi bi-check-circle" aria-hidden="true"></i></span>
        <div><p class="vch-stat-num" data-stat="active">{{ number_format($voucherStats['active']) }}</p><p class="vch-stat-label">Active now</p></div>
    </div>
    <div class="vch-stat">
        <span class="vch-stat-icon is-wheel"><i class="bi bi-bullseye" aria-hidden="true"></i></span>
        <div><p class="vch-stat-num" data-stat="on_wheel">{{ number_format($voucherStats['on_wheel']) }}</p><p class="vch-stat-label">On spin wheel</p></div>
    </div>
    <div class="vch-stat">
        <span class="vch-stat-icon is-expired"><i class="bi bi-hourglass-bottom" aria-hidden="true"></i></span>
        <div><p class="vch-stat-num" data-stat="expired">{{ number_format($voucherStats['expired']) }}</p><p class="vch-stat-label">Expired</p></div>
    </div>
</div>

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

{{-- The Spin Wheel switch is System-Level Configuration (Y | N | N), not a
     voucher action — it turns a customer-facing game on for the whole system.
     Owner only, and admin.game.toggle stays in the `role:admin` route group. --}}
@if($isAdmin)
@php
// The one global switch row, the same one toggleGame() flips and the spin
// endpoint enforces — see Setting::gameEnabled().
$gameEnabled = \App\Models\Setting::gameEnabled();
@endphp
<div class="vch-card vch-wheelbar" data-testid="spin-wheel-bar">
    <div class="vch-wheelbar-text">
        <span class="vch-wheelbar-icon"><i class="bi bi-dice-5" aria-hidden="true"></i></span>
        <div>
            <p class="vch-card-title" style="margin:0;">Spin Wheel</p>
            <p class="vch-card-sub" style="margin:0;">Enable or disable the game for customers</p>
        </div>
    </div>
    <form action="{{ route('admin.game.toggle') }}" method="POST" style="margin:0;">
        @csrf
        <button type="submit"
            style="background:{{ $gameEnabled ? '#4CAF50' : '#ccc' }};color:white;border:none;border-radius:20px;padding:0.4rem 1.2rem;font-size:0.78rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">
            {{ $gameEnabled ? '✓ Enabled' : '✗ Disabled' }}
        </button>
    </form>
</div>
@endif

{{-- "Create Vouchers" is Y | Y | N — the manager tier, unlike the Spin Wheel
     card above. Split out of the owner-only block it used to share. --}}
@if($canAuthorVouchers)
<div class="vch-card" data-testid="create-voucher-card">
    <div class="vch-card-head">
        <p class="vch-card-title">Create New Voucher</p>
        <p class="vch-card-sub">
            @if($lockedBranchId === null)
                Leave as All Branches for a company-wide promo
            @else
                Your voucher is scoped to your own branch
            @endif
        </p>
    </div>
    <form action="{{ route('admin.vouchers.store') }}" method="POST" autocomplete="off">
        @csrf
        {{-- Four columns on a desktop, two on a tablet, one on a phone.
             align-items:start keeps every field top-aligned so the helper
             notes under some inputs never push a whole row out of line. --}}
        <div class="vch-grid">
            <div>
                <label class="form-label-custom" for="vchCode">Voucher Code *</label>
                <input type="text" id="vchCode" name="code" class="form-control-custom" required style="text-transform:uppercase;">
                @error('code')<span style="color:red;font-size:0.75rem;">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="form-label-custom" for="vchType">Discount Type *</label>
                <select id="vchType" name="discount_type" class="form-control-custom" required>
                    <option value="fixed">Fixed (₱)</option>
                    <option value="percent">Percent (%)</option>
                </select>
            </div>
            <div>
                <label class="form-label-custom" for="vchValue">Discount Value *</label>
                <input type="number" id="vchValue" name="discount_value" class="form-control-custom">
            </div>
            <div>
                <label class="form-label-custom" for="vchMax">Max Uses *</label>
                <input type="number" id="vchMax" name="max_uses" class="form-control-custom" min="1" value="100" required>
            </div>
            <div class="vch-wide">
                <label class="form-label-custom" for="vchDesc">Description</label>
                <input type="text" id="vchDesc" name="description" class="form-control-custom">
            </div>
            <div>
                <label class="form-label-custom" for="vchMin">Minimum Order (₱)</label>
                <input type="number" id="vchMin" name="minimum_order" class="form-control-custom" min="0" step="0.01" value="0">
            </div>
            <div>
                <label class="form-label-custom" for="vchPoints">Points Required</label>
                <input type="number" id="vchPoints" name="points_required" class="form-control-custom" min="0" value="0">
                <small class="vch-hint">0 = direct voucher (no points needed)</small>
            </div>
            <div>
                <label class="form-label-custom" for="vchFrom">Valid From (Start Date)</label>
                <input type="date" id="vchFrom" name="valid_from" class="form-control-custom">
                <small class="vch-hint">Date when voucher can be used</small>
            </div>
            <div>
                <label class="form-label-custom" for="vchExpires">Expiry Date</label>
                <input type="date" id="vchExpires" name="expires_at" class="form-control-custom">
            </div>
            {{-- Branch scope. Owner only: a supervisor has exactly one legal
                 answer, so their voucher is scoped from their account by
                 promotionBranchIdFor() and no field is offered. Same plain
                 <select name="branch_id"> as the Assigned Branch control on
                 the Staff Accounts page — no new component. Defaults to
                 global; the owner picks a branch only when they mean to. --}}
            @if($lockedBranchId === null)
            <div>
                <label class="form-label-custom" for="vchBranch">Branch Scope</label>
                <select id="vchBranch" name="branch_id" class="form-control-custom">
                    <option value="">All Branches (global)</option>
                    @foreach(($branches ?? collect()) as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
                <small class="vch-hint">Leave as All Branches for a company-wide promo</small>
            </div>
            @endif
        </div>
        @if($lockedBranchId !== null)
        <p style="font-size:0.75rem;color:#4B5563;font-weight:500;margin:0.9rem 0 0;">
            <i class="bi bi-building"></i>
            This voucher will be scoped to your own branch.
        </p>
        @endif
        <div class="vch-actions">
            <button type="reset" class="vch-btn-ghost">Clear</button>
            <button type="submit" class="btn-primary-custom">
                <i class="bi bi-plus-circle"></i> Create Voucher
            </button>
        </div>
    </form>
</div>
@endif

<div class="vch-card vch-list" data-testid="voucher-list-card">
    <div class="vch-card-head vch-list-head">
        <div>
            <p class="vch-card-title">Vouchers <span class="vch-count" id="vchCount" data-testid="voucher-count">{{ $vouchers->total() }}</span></p>
            <p class="vch-card-sub">Usage, validity and spin wheel status</p>
        </div>
    </div>

    {{-- Search + filters. A plain GET form: the URL carries q / status / scope,
         so a filtered list can be reloaded, bookmarked and paged through, and
         with no JavaScript the Search button (noscript) submits it as a normal
         page load. With JavaScript, the script below fetches the same URL in
         the background and swaps only #vchResults, so this form — and the
         cursor in the search box — is never replaced. Display only — the
         viewer's own scope is applied before any of this. --}}
    @php $filtersOn = $filters['q'] !== '' || $filters['status'] !== '' || $filters['scope'] !== ''; @endphp
    <form action="{{ route('admin.vouchers') }}" method="GET" class="vch-toolbar" role="search" data-testid="voucher-filters">
        <label class="vch-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="100"
                placeholder="Search code or description..." aria-label="Search code or description">
        </label>
        <select name="status" class="form-control-custom vch-filter" aria-label="Filter by status">
            <option value="">All statuses</option>
            @foreach(['active' => 'Active', 'inactive' => 'Inactive', 'expired' => 'Expired', 'used_up' => 'Used up'] as $statusKey => $statusLabel)
                <option value="{{ $statusKey }}" @selected($filters['status'] === $statusKey)>{{ $statusLabel }}</option>
            @endforeach
        </select>
        <select name="scope" class="form-control-custom vch-filter" aria-label="Filter by scope">
            <option value="">All scopes</option>
            <option value="global" @selected($filters['scope'] === 'global')>Global</option>
            @foreach($scopeBranches as $scopeBranch)
                <option value="{{ $scopeBranch->id }}" @selected($filters['scope'] === (string) $scopeBranch->id)>{{ $scopeBranch->name }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="vch-btn-ghost">Search</button></noscript>
        {{-- Always rendered so the script can show/hide it as filters change;
             `hidden` is the server's answer for this load. --}}
        <a href="{{ route('admin.vouchers') }}" class="vch-clear-filters" id="vchClearFilters" data-vch-clear @if(! $filtersOn) hidden @endif>Clear filters</a>
    </form>

    {{-- Shown only when a background update fails; the previous list stays. --}}
    <p class="vch-error" id="vchError" role="alert" hidden>
        Couldn't update the list. Showing the previous results.
        <button type="button" class="vch-error-retry" id="vchRetry">Try again</button>
    </p>

    @include('admin.partials.voucher-list-results', ['vouchers' => $vouchers, 'filters' => $filters])
</div>

{{-- The three-dot menus. One open at a time, positioned against the viewport
     (fixed) so the table's own scrollbox never clips them. It closes on an
     outside click, Escape, a resize, or a real scroll of the page or the table
     -- but not in the first moments after it opens: a scroll that was already
     in flight used to close a fixed popover the frame after it opened. --}}
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
            var btn = e.target.closest ? e.target.closest('[data-vch-menu-btn]') : null;
            if (btn) {
                if (btn === openBtn) { closeMenu(false); } else { openMenu(btn); }
                return;
            }
            if (openBtn && !(e.target.closest && e.target.closest('.vch-menu'))) closeMenu(false);
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
        // The background list refresh replaces the rows, and their menus with
        // them; close any open one first so no stale button is left "open".
        document.addEventListener('vch:before-swap', function () { closeMenu(false); });
        window.addEventListener('scroll', function () {
            if (openBtn && Date.now() - openedAt > 300) closeMenu(false);
        }, true);
    })();
</script>

{{-- Filter without a page reload.

     A change to the search box or either dropdown fetches the SAME address
     (same controller, same middleware, same permission checks) with the new
     query and the header X-Vouchers-Fragment: 1, which makes showVouchers()
     answer with only the results region. That region (#vchResults) is swapped
     in place; the stat cards, the create form and the search box itself are
     never touched, so nothing typed there is lost and the cursor stays put.

     Per-row actions need no re-wiring: the three-dot menu is one delegated
     document listener, Edit is an inline onclick calling a global, and the
     toggle / issue / delete controls are ordinary <form>s inside the swapped
     HTML (each with a fresh CSRF token).

     Nothing here is required for the page to work: the form is a plain GET
     form with a noscript Search button, and every pager link is a real href. --}}
<script>
    (function () {
        var form = document.querySelector('[data-testid="voucher-filters"]');
        var region = document.getElementById('vchResults');
        var errorBox = document.getElementById('vchError');
        if (!form || !region || !errorBox || !window.fetch || !window.URL || !window.URLSearchParams || !window.AbortController) {
            return;
        }

        var countEl = document.getElementById('vchCount');
        var clearEl = document.getElementById('vchClearFilters');
        var searchEl = form.elements.q;
        var DEBOUNCE_MS = 300;
        var timer = null;
        var latest = 0;          // sequence number of the newest request
        var inflight = null;     // its AbortController
        var lastUrl = null;      // what "Try again" repeats

        // The address for the form's current values. It starts from the current
        // address so per_page (and anything else the page was opened with) is
        // kept, drops `page` because a changed filter starts again at page 1,
        // and leaves empty filters out so the URL stays clean.
        function filterUrl() {
            var url = new URL(window.location.href);
            url.searchParams.delete('page');
            ['q', 'status', 'scope'].forEach(function (name) {
                var value = form.elements[name].value.trim();
                if (value === '') { url.searchParams.delete(name); } else { url.searchParams.set(name, value); }
            });
            return url;
        }

        function setBusy(on) {
            region.classList.toggle('is-loading', on);
            region.setAttribute('aria-busy', on ? 'true' : 'false');
        }

        function showError(on) { errorBox.hidden = !on; }

        function load(url) {
            clearTimeout(timer);
            if (inflight) { inflight.abort(); }
            var mine = ++latest;
            var controller = new AbortController();
            inflight = controller;
            lastUrl = url;
            setBusy(true);

            fetch(url.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Vouchers-Fragment': '1', 'Accept': 'text/html' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            })
                .then(function (response) {
                    if (mine !== latest) { return null; }
                    // Sent somewhere else (the login page after a session ended):
                    // let the browser go there for real instead of guessing.
                    if (response.redirected) {
                        window.location.assign(url.href);
                        return null;
                    }
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.text();
                })
                .then(function (html) {
                    if (html === null || mine !== latest) { return; }

                    var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('vchResults');
                    if (!fresh) { throw new Error('no results region in response'); }

                    document.dispatchEvent(new CustomEvent('vch:before-swap'));
                    region.innerHTML = fresh.innerHTML;
                    region.dataset.total = fresh.dataset.total;
                    region.dataset.filtersOn = fresh.dataset.filtersOn;
                    if (countEl) { countEl.textContent = fresh.dataset.total; }
                    if (clearEl) { clearEl.hidden = fresh.dataset.filtersOn !== '1'; }

                    // Only now does the address change, so a failed update never
                    // leaves a URL that does not match the list on screen.
                    window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
                    showError(false);
                    setBusy(false);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') { return; }   // superseded; the newer request owns the state
                    if (mine !== latest) { return; }
                    showError(true);
                    setBusy(false);
                });
        }

        function applyNow() { load(filterUrl()); }

        // Typing: wait for a pause, and ignore anything an older request returns.
        searchEl.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(applyNow, DEBOUNCE_MS);
        });

        // Dropdowns apply straight away.
        form.elements.status.addEventListener('change', applyNow);
        form.elements.scope.addEventListener('change', applyNow);

        // Enter in the search box: apply now, no navigation.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            applyNow();
        });

        // "Clear filters" (toolbar link and the empty-state link).
        document.addEventListener('click', function (e) {
            var clear = e.target.closest ? e.target.closest('[data-vch-clear]') : null;
            if (!clear || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            e.preventDefault();
            form.elements.q.value = '';
            form.elements.status.value = '';
            form.elements.scope.value = '';
            applyNow();
        });

        // Pager links: same background swap, filters already in each href.
        region.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('a.voucher-page-btn') : null;
            if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            var href = link.getAttribute('href');
            if (!href || href === '#' || link.getAttribute('aria-disabled') === 'true') { return; }
            e.preventDefault();
            load(new URL(href, window.location.href));
        });

        document.getElementById('vchRetry').addEventListener('click', function () {
            load(lastUrl || filterUrl());
        });
    })();
</script>


@if($canAuthorVouchers)
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
                {{-- Owner only. A supervisor can only ever reach this modal for
                     a voucher already in their own branch, and updateVoucher()
                     re-derives branch_id from their account regardless of what
                     is posted, so offering them the field would be offering a
                     choice that does not exist. --}}
                @if($lockedBranchId === null)
                <div>
                    <label class="form-label-custom">Branch Scope</label>
                    <select name="branch_id" id="editVoucherBranch" class="form-control-custom">
                        <option value="">All Branches (global)</option>
                        @foreach(($branches ?? collect()) as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
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

        // Absent for a supervisor — the field is not rendered for them.
        var branchField = document.getElementById('editVoucherBranch');
        if (branchField) {
            branchField.value = btn.dataset.branch || '';
        }

        document.getElementById('editVoucherModal').classList.add('show');
    }

    function closeEditVoucherModal() {
        document.getElementById('editVoucherModal').classList.remove('show');
    }
</script>
@endif

@endsection
@extends('admin.layout')

@section('title', 'Account Settings - Peachy Admin')

@section('content')

{{--
    Account Settings — docs/design-refs/admin-account-settings-all-roles.html
    (Defect Report 5, item 3 — Oct 2026).

    WHO SEES WHAT
    -------------
    Every portal role opens this page (route group role:admin,staff,supervisor)
    and every card is about the SIGNED-IN account only: nothing here reads a
    user id from the request.

      Admin       Account Details + Managing staff box, Change Password,
                  GCash Payment (editable, for the branch in the Viewing bar),
                  Recent Sign-ins.
      Supervisor  The same, but GCash is VIEW ONLY and always their own branch.
      Staff       Account Details, Change Password, Recent Sign-ins. No GCash
                  card and no Managing staff box.

    Saving GCash stays owner-only on the server (route group role:admin, and
    BranchGcash::save() refuses anyone else with a 403); hiding the form is
    the convenience, not the control.

    The design's top bar is not copied: the admin layout's sidebar and
    "Viewing:" bar stay as they are. The fonts are the admin shell's own
    (Fraunces + Karla, self-hosted), not the design's Google Fonts.
--}}

@php
    $accountName  = trim((string) ($me->name ?? ''));
    $accountEmail = (string) ($me->email ?? '');
    $accountRole  = (string) ($me->role ?? '');

    // Initials: first letter of the first two words of the name, falling back
    // to the email's first letter for an account with no name.
    $nameWords = preg_split('/\s+/u', $accountName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';
    foreach (array_slice($nameWords, 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }
    if ($initials === '') {
        $initials = mb_strtoupper(mb_substr($accountEmail, 0, 1)) ?: '?';
    }

    $roleLabel = [
        'admin'      => 'Administrator',
        'supervisor' => 'Supervisor',
        'staff'      => 'Staff',
    ][$accountRole] ?? ucfirst($accountRole);

    $isBranchLocked = in_array($accountRole, \App\Models\User::BRANCH_LOCKED_ROLES, true);
    $assignedBranch = $me->branch?->name ?? 'No branch assigned';

    // The Staff Accounts page is open to the manager tier (owner + supervisor).
    $canOpenStaffAccounts = in_array($accountRole, \App\Models\User::MANAGER_ROLES, true);

    $lede = match ($gcashMode) {
        'edit'  => 'Manage your sign-in security and the store payment details customers see at checkout.',
        'view'  => 'Manage your sign-in security and see the GCash payment details of your branch.',
        default => 'Manage your sign-in security and review the recent sign-ins to your account.',
    };

    $passwordErrors = collect(['current_password', 'password'])
        ->flatMap(fn ($field) => $errors->get($field))
        ->all();

    $gcashErrors = collect(['gcash_qr', 'gcash_phone', 'branch_id'])
        ->flatMap(fn ($field) => $errors->get($field))
        ->all();

    // The number is stored as 09XXXXXXXXX; the field shows what follows +63.
    $gcashLocal = function (?string $stored): string {
        $digits = preg_replace('/\D/', '', (string) $stored);
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return trim(substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 4));
    };

    $hasQr = filled($gcash['qr'] ?? null);
    $perPage = 3;
    $signInCount = $signIns->count();
    $signInPages = max(1, (int) ceil($signInCount / $perPage));

    $deviceIcons = [
        'desktop' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'mobile'  => '<rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10 18.5h4"/>',
        'tablet'  => '<rect x="5" y="3" width="14" height="18" rx="2.5"/><path d="M10.5 18.5h3"/>',
    ];
@endphp

<style>
    .acs {
        --acs-red: #9d1c1c;
        --acs-red-dark: #7f1616;
        --acs-coral: #f4845f;
        --acs-peach: #fff1e9;
        --acs-cream: #fffaf7;
        --acs-ink: #342b29;
        --acs-ink-soft: #5d4c47;
        --acs-muted: #9a8a84;
        --acs-line: #f3e3da;
        --acs-line-strong: #e9d5c9;
        --acs-green: #2f855a;
        --acs-green-soft: #e9f7ef;
        --acs-shadow-sm: 0 2px 10px rgba(120, 45, 25, 0.05);
        --acs-radius-lg: 18px;
        --acs-radius-md: 13px;
        --acs-radius-sm: 9px;
        max-width: 1180px;
        margin: 0 auto;
        color: var(--acs-ink);
        font-size: 14px;
    }
    .acs *, .acs *::before, .acs *::after { box-sizing: border-box; }
    .acs [hidden] { display: none !important; }
    .acs .acs-serif { font-family: 'Fraunces', Georgia, serif; }

    /* ── Page header ── */
    .acs-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 24px; flex-wrap: wrap; }
    .acs-eyebrow { font-size: 10.5px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--acs-coral); margin: 0 0 6px; }
    .acs-head h1 { font-size: 32px; font-weight: 700; color: var(--acs-red); line-height: 1.15; margin: 0; }
    .acs-lede { color: var(--acs-muted); font-size: 13.5px; margin: 8px 0 0; max-width: 520px; }
    .acs-head-actions { display: flex; gap: 10px; }

    /* ── Profile banner ── */
    .acs-banner {
        position: relative; overflow: hidden;
        background: linear-gradient(120deg, var(--acs-red) 0%, #b3352a 62%, var(--acs-coral) 130%);
        border-radius: var(--acs-radius-lg); padding: 24px 26px; color: #fff;
        display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
        box-shadow: 0 14px 34px -14px rgba(157, 28, 28, 0.45); margin-bottom: 22px;
    }
    .acs-banner::before, .acs-banner::after { content: ''; position: absolute; border-radius: 50%; background: rgba(255, 255, 255, 0.08); pointer-events: none; }
    .acs-banner::before { width: 220px; height: 220px; top: -110px; right: 80px; }
    .acs-banner::after { width: 150px; height: 150px; bottom: -70px; right: -30px; }
    .acs-avatar {
        width: 66px; height: 66px; border-radius: 20px;
        background: rgba(255, 255, 255, 0.16); border: 1.5px solid rgba(255, 255, 255, 0.45);
        display: grid; place-items: center; font-size: 22px; font-weight: 700;
        flex-shrink: 0; position: relative;
    }
    .acs-online-dot { position: absolute; right: -3px; bottom: -3px; width: 16px; height: 16px; border-radius: 50%; background: #7ce3a8; border: 3px solid #a93426; }
    .acs-banner-text { position: relative; min-width: 0; }
    .acs-banner-text h2 { font-size: 20px; font-weight: 700; margin: 0; color: #fff; overflow-wrap: anywhere; }
    .acs-banner-text p { font-size: 12.5px; opacity: 0.85; margin: 3px 0 0; overflow-wrap: anywhere; }
    .acs-banner-meta { margin-left: auto; display: flex; gap: 10px; flex-wrap: wrap; position: relative; }
    .acs-chip {
        background: rgba(255, 255, 255, 0.14); border: 1px solid rgba(255, 255, 255, 0.28);
        border-radius: 999px; padding: 7px 14px; font-size: 11px; font-weight: 600;
        display: inline-flex; align-items: center; gap: 7px; white-space: nowrap;
    }
    .acs-chip svg { flex-shrink: 0; }

    /* ── Grid & cards ── */
    .acs-layout { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr); gap: 20px; align-items: start; }
    .acs-stack { display: grid; gap: 20px; min-width: 0; }
    .acs-card { background: #fff; border: 1px solid var(--acs-line); border-radius: var(--acs-radius-lg); padding: 24px; box-shadow: var(--acs-shadow-sm); min-width: 0; scroll-margin-top: 16px; }
    .acs-card-head { display: flex; align-items: flex-start; gap: 13px; margin-bottom: 20px; }
    .acs-card-icon { width: 40px; height: 40px; border-radius: 12px; background: var(--acs-peach); color: var(--acs-red); display: grid; place-items: center; flex-shrink: 0; }
    .acs-card-head h3 { font-size: 17px; font-weight: 700; color: var(--acs-ink); margin: 0; }
    .acs-card-head p { font-size: 12px; color: var(--acs-muted); margin: 3px 0 0; line-height: 1.55; }
    .acs-card-head .acs-head-tag { margin-left: auto; flex-shrink: 0; }

    .acs-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 9.5px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; border-radius: 999px; padding: 5px 10px; white-space: nowrap; }
    .acs-tag--peach { background: #ffe9de; color: #bd5638; }
    .acs-tag--green { background: var(--acs-green-soft); color: var(--acs-green); }
    .acs-tag--muted { background: #f4eeea; color: #7d6d67; }

    /* ── Fields ── */
    .acs-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .acs-field--full { grid-column: 1 / -1; }
    .acs label, .acs .acs-label { display: block; font-size: 11px; font-weight: 600; color: var(--acs-ink-soft); margin: 0 0 6px; letter-spacing: 0.2px; }
    .acs-input-wrap { position: relative; }
    .acs-input {
        width: 100%; height: 44px; border: 1.5px solid var(--acs-line-strong); border-radius: var(--acs-radius-sm);
        padding: 0 14px; font: inherit; font-size: 13px; color: var(--acs-ink); background: #fff; outline: none;
        transition: border-color 0.2s, box-shadow 0.2s, background 0.2s; margin: 0;
    }
    .acs-input::placeholder { color: #c3b2ab; }
    .acs-input:focus { border-color: var(--acs-coral); box-shadow: 0 0 0 4px rgba(244, 132, 95, 0.15); }
    .acs-input[readonly] { background: #faf5f1; color: var(--acs-ink-soft); cursor: default; }
    .acs-input[readonly]:focus { border-color: var(--acs-line-strong); box-shadow: none; }
    /* The password inputs carry `readonly` until focused (autofill defence), so
       they must not look like the read-only details above. */
    .acs-pw .acs-input[readonly] { background: #fff; color: var(--acs-ink); cursor: text; }
    .acs-input.is-invalid { border-color: #d97373; }
    .acs-input.is-invalid:focus { box-shadow: 0 0 0 4px rgba(217, 115, 115, 0.14); }

    .acs-helper { display: flex; align-items: flex-start; gap: 7px; font-size: 11.5px; color: var(--acs-muted); margin: 14px 0 0; line-height: 1.6; }
    .acs-helper svg { flex-shrink: 0; margin-top: 2px; }

    /* ── Password ── */
    .acs-pw { display: grid; gap: 14px; }
    .acs-pw .pw-field > input { padding-right: 46px; }
    .acs-pw .pw-field > .js-pw-toggle { right: 6px; width: 34px; height: 34px; border-radius: 8px; color: var(--acs-muted); font-size: 16px; }
    .acs-pw .pw-field > .js-pw-toggle:hover { color: var(--acs-red); background: var(--acs-peach); }
    .acs-strength { margin-top: 8px; }
    .acs-strength-bars { display: flex; gap: 5px; }
    .acs-strength-bars i { flex: 1; height: 4px; border-radius: 99px; background: var(--acs-line); transition: background 0.25s; }
    .acs-strength-label { font-size: 10.5px; font-weight: 600; color: var(--acs-muted); margin-top: 6px; display: flex; justify-content: space-between; }
    .acs-field-error { font-size: 11px; color: #b53a3a; margin: 5px 0 0; }

    /* ── Buttons ── */
    .acs-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
    .acs-btn {
        display: inline-flex; align-items: center; gap: 8px; border: 0; border-radius: var(--acs-radius-sm);
        padding: 11px 18px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
        transition: transform 0.15s, box-shadow 0.2s, background 0.2s; text-decoration: none; line-height: 1.2;
    }
    .acs-btn:active { transform: translateY(1px); }
    .acs-btn:focus-visible { outline: 2px solid var(--acs-red); outline-offset: 2px; }
    .acs-btn-primary { background: var(--acs-red); color: #fff; box-shadow: 0 6px 16px -6px rgba(157, 28, 28, 0.5); }
    .acs-btn-primary:hover { background: var(--acs-red-dark); color: #fff; }
    .acs-btn-light { background: #ffe9de; color: var(--acs-red); }
    .acs-btn-light:hover { background: #ffdcc9; color: var(--acs-red); }
    .acs-btn-ghost { background: #fff; color: var(--acs-red); border: 1.5px solid var(--acs-line-strong); }
    .acs-btn-ghost:hover { background: var(--acs-peach); color: var(--acs-red); }
    .acs-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }

    /* ── Notices ── */
    .acs-notice { display: flex; align-items: flex-start; gap: 9px; margin-bottom: 16px; padding: 11px 14px; border-radius: var(--acs-radius-sm); font-size: 12.5px; line-height: 1.5; background: var(--acs-peach); color: var(--acs-ink-soft); }
    .acs-notice svg { flex-shrink: 0; margin-top: 2px; }
    .acs-notice--ok { background: var(--acs-green-soft); color: #276b46; }
    .acs-notice--error { background: #fdeceb; color: #8f2424; }
    .acs-notice--error div + div { margin-top: 2px; }

    /* ── Cross link ── */
    .acs-cross-link {
        margin-top: 18px; padding: 13px 15px; border: 1.5px dashed #eac4b1; border-radius: var(--acs-radius-md);
        background: linear-gradient(135deg, var(--acs-peach), #fff7f2);
        display: flex; align-items: center; justify-content: space-between; gap: 12px; transition: border-color 0.2s;
    }
    .acs-cross-link:hover { border-color: var(--acs-coral); }
    .acs-cross-link b { display: block; font-size: 12.5px; margin-bottom: 2px; }
    .acs-cross-link span { font-size: 11px; color: var(--acs-muted); }
    .acs-cross-link a { color: var(--acs-red); font-size: 11.5px; font-weight: 700; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px; }
    .acs-cross-link a:hover { text-decoration: underline; }

    /* ── GCash ── */
    .acs-gcash-branch { font-size: 12px; color: var(--acs-ink-soft); margin: 4px 0 0; }
    .acs-gcash-grid { display: grid; grid-template-columns: 190px minmax(0, 1fr); gap: 20px; align-items: start; }
    .acs-gcash-fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
    .acs-gcash-fieldset:disabled { opacity: .55; }
    .acs-gcash-fieldset:disabled .acs-drop { cursor: not-allowed; }
    .acs-qr-panel {
        display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 15px 13px;
        border: 1px solid var(--acs-line); border-radius: var(--acs-radius-md);
        background: linear-gradient(160deg, var(--acs-cream), var(--acs-peach) 70%, #fff7f2); text-align: center;
    }
    .acs-qr-kicker { margin: 0; color: var(--acs-muted); font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
    .acs-qr-frame { width: 100%; aspect-ratio: 1 / 1; padding: 9px; border: 1px dashed var(--acs-line-strong); border-radius: 12px; background: #fff; display: grid; place-items: center; overflow: hidden; }
    .acs-qr-frame img { display: block; width: 100%; height: 100%; object-fit: contain; }
    .acs-qr-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; color: #c3b2ab; font-size: 11px; font-weight: 600; line-height: 1.45; }
    .acs-qr-status { font-size: 11px; font-weight: 700; color: var(--acs-coral); }
    .acs-qr-status.is-ready { color: #16835b; }
    .acs-qr-hint { margin: 0; font-size: 10.5px; color: var(--acs-muted); line-height: 1.5; }
    .acs-gcash-controls { min-width: 0; }
    .acs-num-field { display: flex; align-items: stretch; border: 1.5px solid var(--acs-line-strong); border-radius: var(--acs-radius-sm); background: #fff; overflow: hidden; transition: border-color 0.2s, box-shadow 0.2s; }
    .acs-num-field:focus-within { border-color: var(--acs-coral); box-shadow: 0 0 0 4px rgba(244, 132, 95, 0.15); }
    .acs-num-field.is-invalid { border-color: #d97373; }
    .acs-num-prefix { display: grid; place-items: center; padding: 0 12px; background: #f6f1ec; border-right: 1.5px solid var(--acs-line-strong); font-size: 13px; font-weight: 600; color: var(--acs-ink-soft); }
    .acs-num-field input { flex: 1; min-width: 0; height: 44px; border: 0; padding: 0 14px; font: inherit; font-size: 14px; letter-spacing: 0.8px; color: var(--acs-ink); background: transparent; outline: none; }
    .acs-num-field input::placeholder { color: #c3b2ab; letter-spacing: 0.4px; }
    .acs-drop {
        display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 20px 14px;
        border: 1.5px dashed #edc8b9; border-radius: var(--acs-radius-md); background: #fffaf8; text-align: center;
        cursor: pointer; transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .acs-drop svg { color: var(--acs-coral); margin-bottom: 2px; }
    .acs-drop b { font-size: 12.5px; font-weight: 600; color: var(--acs-ink); overflow-wrap: anywhere; }
    .acs-drop span { font-size: 11px; color: var(--acs-muted); }
    .acs-drop:hover, .acs-drop:focus-visible { border-color: var(--acs-coral); background: var(--acs-peach); }
    .acs-drop:focus-visible { outline: 2px solid var(--acs-coral); outline-offset: 2px; }
    .acs-drop.is-dragging { border-color: var(--acs-coral); background: var(--acs-peach); box-shadow: 0 0 0 4px rgba(244, 132, 95, 0.12); }
    .acs-drop.is-invalid { border-color: #d97373; }
    .acs-gcash-state { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 16px; font-size: 11.5px; color: var(--acs-muted); line-height: 1.5; }
    .acs-view-value { min-height: 44px; display: flex; align-items: center; padding: 0 14px; border: 1.5px solid var(--acs-line-strong); border-radius: var(--acs-radius-sm); background: #faf5f1; color: var(--acs-ink-soft); font-size: 14px; letter-spacing: 0.6px; overflow-wrap: anywhere; }
    .acs-view-value--empty { color: var(--acs-muted); letter-spacing: 0; font-size: 13px; }

    /* ── Recent sign-ins ── */
    .acs-session-row { display: flex; align-items: center; gap: 11px; padding: 11px 0; border-bottom: 1px solid var(--acs-line); font-size: 12.5px; }
    .acs-session-row.is-last { border-bottom: 0; padding-bottom: 2px; }
    .acs-session-row > svg { color: var(--acs-red); flex-shrink: 0; }
    .acs-session-row .acs-grow { flex: 1; min-width: 0; }
    .acs-session-row b { display: block; overflow-wrap: anywhere; }
    .acs-session-row small { display: block; color: var(--acs-muted); font-size: 11px; overflow-wrap: anywhere; }
    .acs-empty { font-size: 12.5px; color: var(--acs-muted); margin: 0; line-height: 1.6; }
    .acs-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 12px; padding-top: 13px; border-top: 1px solid var(--acs-line); }
    .acs-pager-info { font-size: 11px; color: var(--acs-muted); }
    .acs-pager-btns { display: flex; gap: 6px; flex-wrap: wrap; }
    .acs-page-btn { min-width: 30px; height: 30px; padding: 0 8px; border: 1.5px solid var(--acs-line-strong); border-radius: 9px; background: #fff; color: var(--acs-ink-soft); font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.2s, color 0.2s, border-color 0.2s; }
    .acs-page-btn:hover:not(:disabled):not(.is-active) { background: var(--acs-peach); }
    .acs-page-btn.is-active { background: var(--acs-red); border-color: var(--acs-red); color: #fff; }
    .acs-page-btn:disabled { opacity: 0.4; cursor: default; }
    .acs-page-nav { font-size: 15px; line-height: 1; }

    /* ── Responsive (the design's two breakpoints) ── */
    @media (max-width: 979.98px) {
        .acs-layout { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 640px) {
        .acs-head h1 { font-size: 26px; }
        .acs-card { padding: 19px; }
        .acs-fields { grid-template-columns: minmax(0, 1fr); }
        .acs-field--full { grid-column: auto; }
        .acs-banner { padding: 20px; }
        .acs-banner-meta { margin-left: 0; }
        .acs-head-actions { width: 100%; }
        .acs-head-actions .acs-btn { flex: 1; justify-content: center; }
        .acs-cross-link { flex-direction: column; align-items: flex-start; }
    }
    @media (max-width: 560px) {
        .acs-gcash-grid { grid-template-columns: minmax(0, 1fr); }
        .acs-qr-panel { max-width: 220px; width: 100%; margin: 0 auto; }
    }
</style>

<div class="acs" data-account-role="{{ $accountRole }}">

    {{-- ══════════════ PAGE HEADER ══════════════ --}}
    <div class="acs-head">
        <div>
            <p class="acs-eyebrow">Administration</p>
            <h1>Account Settings</h1>
            <p class="acs-lede">{{ $lede }}</p>
        </div>
        <div class="acs-head-actions">
            <a class="acs-btn acs-btn-ghost" href="{{ route('admin.home') }}">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12l9-9 9 9"/><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/></svg>
                Dashboard
            </a>
            <a class="acs-btn acs-btn-light" href="#change-password" data-scroll-to="change-password">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                Change Password
            </a>
        </div>
    </div>

    {{-- ══════════════ PROFILE BANNER ══════════════ --}}
    <section class="acs-banner" aria-label="Signed-in profile">
        <div class="acs-avatar acs-serif" aria-hidden="true">{{ $initials }}<span class="acs-online-dot" title="Signed in"></span></div>
        <div class="acs-banner-text">
            <h2 data-profile-name>{{ $accountName !== '' ? $accountName : $accountEmail }}</h2>
            <p data-profile-email>{{ $accountEmail }}</p>
        </div>
        <div class="acs-banner-meta">
            <span class="acs-chip" data-profile-role>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/></svg>
                {{ $roleLabel }}
            </span>
            @if($signedInAt)
                <span class="acs-chip" data-signed-in-ago>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <time datetime="{{ $signedInAt->toIso8601String() }}">Signed in {{ \App\Support\SignInLog::signedInAgo($signedInAt) }}</time>
                </span>
            @endif
        </div>
    </section>

    <div class="acs-layout">

        {{-- ══════════════ LEFT COLUMN ══════════════ --}}
        <div class="acs-stack">

            {{-- ── Account details ── --}}
            <section class="acs-card" id="account-details" aria-labelledby="account-details-title">
                <div class="acs-card-head">
                    <span class="acs-card-icon" aria-hidden="true">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>
                    </span>
                    <div>
                        <h3 id="account-details-title">Account Details</h3>
                        <p>Your account information and assigned access level.</p>
                    </div>
                    <span class="acs-head-tag acs-tag acs-tag--peach">Read-only</span>
                </div>

                <div class="acs-fields">
                    <div>
                        <label for="acc_name">Name</label>
                        <input class="acs-input" id="acc_name" type="text" value="{{ $accountName }}" readonly autocomplete="off">
                    </div>
                    <div>
                        <label for="acc_email">Email Address</label>
                        <input class="acs-input" id="acc_email" type="email" value="{{ $accountEmail }}" readonly autocomplete="off">
                    </div>
                    <div class="{{ $isBranchLocked ? '' : 'acs-field--full' }}">
                        <label for="acc_role">Access Level</label>
                        <input class="acs-input" id="acc_role" type="text" value="{{ $roleLabel }}" readonly autocomplete="off">
                    </div>
                    @if($isBranchLocked)
                        <div>
                            <label for="acc_branch">Assigned Branch</label>
                            <input class="acs-input" id="acc_branch" type="text" value="{{ $assignedBranch }}" readonly autocomplete="off">
                        </div>
                    @endif
                </div>

                <p class="acs-helper">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>
                    These details are managed by an administrator and cannot be edited here.
                </p>

                @if($canOpenStaffAccounts)
                    <div class="acs-cross-link" data-staff-accounts-link>
                        <div>
                            <b>Managing staff accounts?</b>
                            <span>Add users, update access levels, or activate and deactivate accounts.</span>
                        </div>
                        <a href="{{ route('admin.users') }}">Go to Staff Accounts
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7"/><path d="M9 7h8v8"/></svg>
                        </a>
                    </div>
                @endif
            </section>

            {{-- ── Change password ──
                 Always the SIGNED-IN account: the form carries no user id, and
                 AdminController::updateOwnPassword() reads the account from the
                 admin guard. Field names and the autofill defences are the ones
                 AdminFormHygieneTest pins. --}}
            <section class="acs-card" id="change-password" aria-labelledby="pw-title">
                <div class="acs-card-head">
                    <span class="acs-card-icon" aria-hidden="true">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1.4" fill="currentColor" stroke="none"/></svg>
                    </span>
                    <div>
                        <h3 id="pw-title">Change Password</h3>
                        <p>Update the password for your signed-in account. You will stay signed in on this device.</p>
                    </div>
                </div>

                @if(session('password_success'))
                    <div class="acs-notice acs-notice--ok" role="status" data-card-notice>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>
                        <span>{{ session('password_success') }}</span>
                    </div>
                @endif

                @if(!empty($passwordErrors))
                    <div class="acs-notice acs-notice--error" role="alert" data-card-notice data-password-errors>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg>
                        <div>
                            @foreach($passwordErrors as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <form id="pwForm" action="{{ route('admin.account.password.update') }}" method="POST" autocomplete="off" novalidate>
                    <input type="text" name="prevent_autofill_username" id="prevent_autofill_username" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
                    <input type="password" name="prevent_autofill_password" id="prevent_autofill_password" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
                    @csrf @method('PUT')

                    <div class="acs-pw">
                        <div>
                            <label for="current_password">Current Password</label>
                            <div class="pw-field">
                                <input id="current_password" type="password" name="current_password" class="acs-input @error('current_password') is-invalid @enderror"
                                       placeholder="Enter current password"
                                       readonly onfocus="this.removeAttribute('readonly');"
                                       autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" required>
                                <button type="button" class="js-pw-toggle" data-target="current_password"
                                    aria-label="Show password" aria-pressed="false" title="Show password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="acs-field-error" id="err_current_password" hidden>Enter your current password.</p>
                        </div>
                        <div>
                            <label for="new_password">New Password</label>
                            <div class="pw-field">
                                <input id="new_password" type="password" name="password" class="acs-input @error('password') is-invalid @enderror"
                                       placeholder="Enter new password"
                                       readonly onfocus="this.removeAttribute('readonly');"
                                       autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                                       minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}" maxlength="{{ \App\Support\PasswordPolicy::MAX_LENGTH }}" required>
                                <button type="button" class="js-pw-toggle" data-target="new_password"
                                    aria-label="Show password" aria-pressed="false" title="Show password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="acs-field-error" id="err_new_password" hidden></p>
                            <div class="acs-strength" id="pwStrength" hidden>
                                <div class="acs-strength-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                                <div class="acs-strength-label"><span id="pwStrengthText">Password strength</span><span id="pwStrengthPct"></span></div>
                            </div>
                        </div>
                        <div>
                            <label for="new_password_confirmation">Confirm New Password</label>
                            <div class="pw-field">
                                <input id="new_password_confirmation" type="password" name="password_confirmation" class="acs-input @error('password') is-invalid @enderror"
                                       placeholder="Re-enter new password"
                                       readonly onfocus="this.removeAttribute('readonly');"
                                       autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                                       minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}" maxlength="{{ \App\Support\PasswordPolicy::MAX_LENGTH }}" required>
                                <button type="button" class="js-pw-toggle" data-target="new_password_confirmation"
                                    aria-label="Show password" aria-pressed="false" title="Show password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="acs-field-error" id="err_new_password_confirmation" hidden>Passwords do not match.</p>
                        </div>
                    </div>

                    <p class="acs-helper">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>
                        <span>{{ \App\Support\PasswordPolicy::describe() }} Your new password must be different from your current password.</span>
                    </p>

                    <div class="acs-actions">
                        <button type="reset" class="acs-btn acs-btn-light" id="pwClear">Clear</button>
                        <button type="submit" class="acs-btn acs-btn-primary">
                            Update Password
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
                        </button>
                    </div>
                </form>
            </section>
        </div>

        {{-- ══════════════ RIGHT COLUMN ══════════════ --}}
        <div class="acs-stack">

            {{-- ── GCash payment ──
                 'edit' (owner): the branch in the Viewing bar; All Branches
                 disables the form and posts no branch.
                 'view' (supervisor): their own branch, read-only, no form.
                 Staff: no card at all ($gcashMode null). --}}
            @if($gcashMode === 'edit')
                <section class="acs-card" id="gcash-settings" data-gcash-mode="edit" aria-labelledby="gcash-title">
                    <div class="acs-card-head">
                        <span class="acs-card-icon" aria-hidden="true">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19"/><path d="M6 15h4"/></svg>
                        </span>
                        <div>
                            <h3 id="gcash-title">GCash Payment</h3>
                            <p>The GCash number and QR code customers use to pay for their orders.</p>
                            <p class="acs-gcash-branch" data-gcash-branch>
                                @if($gcashBranch)
                                    Branch: <strong>{{ $gcashBranch->name }}</strong>
                                @endif
                            </p>
                        </div>
                        <span class="acs-head-tag acs-tag acs-tag--green">Payment</span>
                    </div>

                    @unless($gcashBranch)
                        <div class="acs-notice" role="status" data-gcash-select-branch>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h1M14 9h1M9 13h1M14 13h1M9 17h1M14 17h1"/></svg>
                            <span>Select a branch to manage its GCash.</span>
                        </div>
                    @endunless

                    @if(session('gcash_success'))
                        <div class="acs-notice acs-notice--ok" role="status" data-card-notice>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>
                            <span>{{ session('gcash_success') }}</span>
                        </div>
                    @endif

                    @if(!empty($gcashErrors))
                        <div class="acs-notice acs-notice--error" role="alert" data-card-notice data-gcash-errors>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg>
                            <div>
                                @foreach($gcashErrors as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <form id="gcashForm" action="{{ route('admin.account.gcash-qr.update') }}" method="POST" enctype="multipart/form-data" autocomplete="off" novalidate>
                        @csrf
                        @if($gcashBranch)
                            <input type="hidden" name="branch_id" value="{{ $gcashBranch->id }}">
                        @endif

                        <fieldset class="acs-gcash-fieldset" @disabled(!$gcashBranch)>
                            <div class="acs-gcash-grid">
                                <div class="acs-qr-panel">
                                    <p class="acs-qr-kicker">QR at checkout</p>
                                    <div class="acs-qr-frame">
                                        <div class="acs-qr-empty" id="qrEmptyState" @if($hasQr) hidden @endif>
                                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3z"/><path d="M20 14v3M14 20h3M20 20h.01"/></svg>
                                            <span>No QR code<br>uploaded yet</span>
                                        </div>
                                        <img id="gcashQrPreview" alt="Current GCash QR code"
                                             @if($hasQr) src="{{ \App\Support\Img::url($gcash['qr']) }}" @else hidden @endif>
                                    </div>
                                    <span class="acs-qr-status {{ $hasQr ? 'is-ready' : '' }}" id="qrStatus">{{ $hasQr ? '● QR ready' : '● Not uploaded' }}</span>
                                    <p class="acs-qr-hint">Customers scan this QR to send their payment.</p>
                                </div>

                                <div class="acs-gcash-controls">
                                    <label for="gcashNumber">GCash mobile number</label>
                                    <div class="acs-num-field @error('gcash_phone') is-invalid @enderror" id="numField">
                                        <span class="acs-num-prefix">+63</span>
                                        <input id="gcashNumber" type="tel" name="gcash_phone" inputmode="numeric"
                                               value="{{ old('gcash_phone', $gcashLocal($gcash['number'] ?? null)) }}"
                                               placeholder="912 345 6789" maxlength="20" autocomplete="off" aria-describedby="errGcashNumber">
                                    </div>
                                    <p class="acs-field-error" id="errGcashNumber" hidden>Enter a valid PH mobile number: 10 digits after +63, starting with 9.</p>

                                    <label style="margin-top:16px" for="gcashQrUpload">GCash QR image</label>
                                    <div class="acs-drop @error('gcash_qr') is-invalid @enderror" id="gcashDrop" role="button" tabindex="0" aria-label="Upload GCash QR image">
                                        <input id="gcashQrUpload" type="file" name="gcash_qr" accept="image/png,image/jpeg,image/webp" hidden>
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                                        <b id="dropTitle">{{ $hasQr ? 'QR image saved — click to replace' : 'Click to upload QR image' }}</b>
                                        <span>or drag &amp; drop · PNG, JPG, WEBP · up to 5 MB</span>
                                    </div>
                                    <p class="acs-field-error" id="errGcashQr" hidden></p>

                                    @if($gcashBranch)
                                        <p class="acs-gcash-state" data-gcash-state>
                                            <span class="acs-tag {{ $gcash['enabled'] ? 'acs-tag--green' : 'acs-tag--muted' }}">GCash {{ $gcash['enabled'] ? 'On' : 'Off' }}</span>
                                            <span>Turn GCash on or off for this branch on the <a href="{{ route('admin.branches') }}">Branches</a> page.</span>
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="acs-actions">
                                <button type="submit" class="acs-btn acs-btn-primary" id="gcashSave">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>
                                    Save GCash Details
                                </button>
                            </div>
                        </fieldset>
                    </form>
                </section>
            @elseif($gcashMode === 'view')
                <section class="acs-card" id="gcash-settings" data-gcash-mode="view" aria-labelledby="gcash-title">
                    <div class="acs-card-head">
                        <span class="acs-card-icon" aria-hidden="true">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19"/><path d="M6 15h4"/></svg>
                        </span>
                        <div>
                            <h3 id="gcash-title">GCash Payment</h3>
                            <p>The GCash number and QR code customers use to pay for their orders.</p>
                            <p class="acs-gcash-branch" data-gcash-branch>Branch: <strong>{{ $gcashBranch?->name ?? 'No branch assigned' }}</strong></p>
                        </div>
                        <span class="acs-head-tag acs-tag acs-tag--peach">View only</span>
                    </div>

                    <div class="acs-gcash-grid">
                        <div class="acs-qr-panel">
                            <p class="acs-qr-kicker">QR at checkout</p>
                            <div class="acs-qr-frame">
                                @if($hasQr)
                                    <img src="{{ \App\Support\Img::url($gcash['qr']) }}" alt="Current GCash QR code">
                                @else
                                    <div class="acs-qr-empty">
                                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3z"/><path d="M20 14v3M14 20h3M20 20h.01"/></svg>
                                        <span>No QR code<br>uploaded yet</span>
                                    </div>
                                @endif
                            </div>
                            <span class="acs-qr-status {{ $hasQr ? 'is-ready' : '' }}">{{ $hasQr ? '● QR ready' : '● Not uploaded' }}</span>
                            <p class="acs-qr-hint">Customers scan this QR to send their payment.</p>
                        </div>

                        <div class="acs-gcash-controls">
                            <span class="acs-label">GCash mobile number</span>
                            @if(filled($gcash['number'] ?? null))
                                <div class="acs-view-value" data-gcash-number>+63 {{ $gcashLocal($gcash['number']) }}</div>
                            @else
                                <div class="acs-view-value acs-view-value--empty" data-gcash-number>Not set</div>
                            @endif

                            <span class="acs-label" style="margin-top:16px">GCash at checkout</span>
                            <p class="acs-gcash-state" style="margin-top:0" data-gcash-state>
                                <span class="acs-tag {{ $gcash['enabled'] ? 'acs-tag--green' : 'acs-tag--muted' }}">GCash {{ $gcash['enabled'] ? 'On' : 'Off' }}</span>
                                <span>{{ $gcash['available'] ? 'Customers can pay with GCash.' : 'Customers cannot pay with GCash right now.' }}</span>
                            </p>

                            <p class="acs-helper" data-gcash-owner-only>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                                Only the owner can change GCash details.
                            </p>
                        </div>
                    </div>
                </section>
            @endif

            {{-- ── Recent sign-ins ──
                 The signed-in user's own rows only (SignInLog::recentFor()).
                 All of them (at most 20) are in the markup; page 1 is shown and
                 the pager script shows the others three at a time. --}}
            <section class="acs-card" id="recent-sign-ins" aria-labelledby="session-title">
                <div class="acs-card-head">
                    <span class="acs-card-icon" aria-hidden="true">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
                    </span>
                    <div>
                        <h3 id="session-title">Recent Sign-ins</h3>
                        <p>The last devices that accessed this account.</p>
                    </div>
                </div>

                @if($signInCount === 0)
                    <p class="acs-empty" data-sign-ins-empty>No sign-ins recorded yet. Your next sign-in will appear here.</p>
                @else
                    <div id="deviceList" aria-live="polite" data-per-page="{{ $perPage }}" data-total="{{ $signInCount }}">
                        @foreach($signIns as $i => $signIn)
                            @php
                                $page = intdiv($i, $perPage) + 1;
                                $isLastOnPage = ($i % $perPage) === $perPage - 1 || $i === $signInCount - 1;
                            @endphp
                            <div class="acs-session-row {{ $isLastOnPage ? 'is-last' : '' }}" data-sign-in-row data-page="{{ $page }}" @if($page !== 1) hidden @endif>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $deviceIcons[$signIn->device_type] ?? $deviceIcons['desktop'] !!}</svg>
                                <div class="acs-grow">
                                    <b>{{ $signIn->browser }} · {{ $signIn->os }}</b>
                                    <small>{{ \App\Support\SignInLog::maskIp($signIn->ip_address) }} · <time datetime="{{ $signIn->signed_in_at->toIso8601String() }}">{{ \App\Support\SignInLog::whenLabel($signIn->signed_in_at) }}</time></small>
                                </div>
                                @if($currentSignInId !== null && $signIn->id === $currentSignInId)
                                    <span class="acs-tag acs-tag--green" data-this-device>This device</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="acs-pager">
                        <span class="acs-pager-info" id="pagerInfo">Showing 1–{{ min($perPage, $signInCount) }} of {{ $signInCount }} {{ $signInCount === 1 ? 'device' : 'devices' }}</span>
                        <div class="acs-pager-btns" id="pagerBtns">
                            <button type="button" class="acs-page-btn acs-page-nav" data-page="prev" aria-label="Previous page" disabled>&lsaquo;</button>
                            @for($p = 1; $p <= $signInPages; $p++)
                                <button type="button" class="acs-page-btn {{ $p === 1 ? 'is-active' : '' }}" data-page="{{ $p }}" aria-label="Page {{ $p }}" @if($p === 1) aria-current="page" @endif>{{ $p }}</button>
                            @endfor
                            <button type="button" class="acs-page-btn acs-page-nav" data-page="next" aria-label="Next page" @disabled($signInPages === 1)>&rsaquo;</button>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ── Viewing bar (owner): switch the branch the GCash card follows ── */
    @if($gcashMode === 'edit')
    window.pcBranchSelectHandler = function (value) {
        window.location.href = @json(route('admin.account')) + '?branch=' + encodeURIComponent(value);
        return true;
    };
    @endif

    /* ── Bring a card with a server message into view after a save ── */
    var notice = document.querySelector('.acs [data-card-notice]');
    if (notice && notice.scrollIntoView) {
        notice.closest('.acs-card').scrollIntoView({ block: 'start' });
    }

    /* ── Password strength meter ── */
    var pwForm = document.getElementById('pwForm');
    var currentPw = document.getElementById('current_password');
    var newPw = document.getElementById('new_password');
    var confirmPw = document.getElementById('new_password_confirmation');
    var strengthBox = document.getElementById('pwStrength');
    var strengthText = document.getElementById('pwStrengthText');
    var strengthPct = document.getElementById('pwStrengthPct');
    var bars = strengthBox.querySelectorAll('i');
    var errCurrent = document.getElementById('err_current_password');
    var errNew = document.getElementById('err_new_password');
    var errConfirm = document.getElementById('err_new_password_confirmation');
    var MIN = {{ \App\Support\PasswordPolicy::MIN_LENGTH }};
    var MAX = {{ \App\Support\PasswordPolicy::MAX_LENGTH }};
    var POLICY = @json(\App\Support\PasswordPolicy::describe());
    var LEVELS = [
        { label: 'Too weak', color: '#e07070' },
        { label: 'Weak', color: '#e0a070' },
        { label: 'Good', color: '#e5b95c' },
        { label: 'Strong', color: '#4caf7d' },
        { label: 'Excellent', color: '#2f855a' }
    ];

    function chars(v) { return Array.from(v).length; }

    // The same rule the server applies (App\Support\PasswordPolicy): length,
    // an uppercase and a lowercase letter, a number and a symbol.
    function meetsPolicy(v) {
        var n = chars(v);
        return n >= MIN && n <= MAX && /\p{Lu}/u.test(v) && /\p{Ll}/u.test(v) &&
            /\p{N}/u.test(v) && /[\p{P}\p{S}\p{Z}]/u.test(v);
    }

    newPw.addEventListener('input', function () {
        var v = newPw.value;
        strengthBox.hidden = v.length === 0;
        if (!v.length) { return; }

        var score = 0;
        if (chars(v) >= 8) { score++; }
        if (chars(v) >= 12) { score++; }
        if (/\p{Lu}/u.test(v) && /\p{Ll}/u.test(v)) { score++; }
        if (/\p{N}/u.test(v)) { score++; }
        if (/[\p{P}\p{S}\p{Z}]/u.test(v)) { score++; }
        score = Math.min(score, 5);

        var level = LEVELS[Math.max(0, Math.ceil(score / 5 * 4) - 1)];
        bars.forEach(function (bar, i) {
            bar.style.background = i < score ? level.color : '';
        });
        strengthText.textContent = level.label;
        strengthPct.textContent = Math.round(score / 5 * 100) + '%';
    });

    function setError(input, errEl, message) {
        input.classList.toggle('is-invalid', !!message);
        if (message) { errEl.textContent = message; }
        errEl.hidden = !message;
        return !message;
    }

    pwForm.addEventListener('submit', function (event) {
        var ok = true;
        ok = setError(currentPw, errCurrent, currentPw.value.length === 0 ? 'Enter your current password.' : '') && ok;

        var newMessage = '';
        if (!meetsPolicy(newPw.value)) {
            newMessage = POLICY;
        } else if (newPw.value === currentPw.value) {
            newMessage = 'Your new password must be different from your current password.';
        }
        ok = setError(newPw, errNew, newMessage) && ok;
        ok = setError(confirmPw, errConfirm,
            confirmPw.value.length === 0 || confirmPw.value !== newPw.value ? 'Passwords do not match.' : '') && ok;

        if (!ok) { event.preventDefault(); }
    });

    pwForm.addEventListener('reset', function () {
        strengthBox.hidden = true;
        [currentPw, newPw, confirmPw].forEach(function (input) { input.classList.remove('is-invalid'); });
        [errCurrent, errNew, errConfirm].forEach(function (el) { el.hidden = true; });
        document.querySelectorAll('#change-password [data-card-notice]').forEach(function (el) { el.hidden = true; });
    });

    [[currentPw, errCurrent], [newPw, errNew], [confirmPw, errConfirm]].forEach(function (pair) {
        pair[0].addEventListener('input', function () {
            pair[0].classList.remove('is-invalid');
            pair[1].hidden = true;
        });
    });

    /* ── Recent sign-ins pager ── */
    var list = document.getElementById('deviceList');
    if (list) {
        var rows = list.querySelectorAll('[data-sign-in-row]');
        var perPage = parseInt(list.getAttribute('data-per-page'), 10) || 3;
        var total = rows.length;
        var pages = Math.max(1, Math.ceil(total / perPage));
        var info = document.getElementById('pagerInfo');
        var btns = document.getElementById('pagerBtns');
        var current = 1;

        function show(page) {
            current = page;
            rows.forEach(function (row) {
                row.hidden = parseInt(row.getAttribute('data-page'), 10) !== page;
            });
            var start = (page - 1) * perPage;
            info.textContent = 'Showing ' + (start + 1) + '–' + Math.min(start + perPage, total) +
                ' of ' + total + (total === 1 ? ' device' : ' devices');
            btns.querySelectorAll('.acs-page-btn').forEach(function (b) {
                var target = b.getAttribute('data-page');
                if (target === 'prev') { b.disabled = page === 1; return; }
                if (target === 'next') { b.disabled = page === pages; return; }
                var isActive = parseInt(target, 10) === page;
                b.classList.toggle('is-active', isActive);
                if (isActive) { b.setAttribute('aria-current', 'page'); } else { b.removeAttribute('aria-current'); }
            });
        }

        btns.addEventListener('click', function (event) {
            var b = event.target.closest('.acs-page-btn');
            if (!b || b.disabled) { return; }
            var target = b.getAttribute('data-page');
            var page = target === 'prev' ? current - 1 : target === 'next' ? current + 1 : parseInt(target, 10);
            if (page >= 1 && page <= pages && page !== current) { show(page); }
        });
    }

    /* ── GCash (owner only) ── */
    var gcashForm = document.getElementById('gcashForm');
    if (!gcashForm) { return; }

    var fieldset = gcashForm.querySelector('fieldset');
    var numField = document.getElementById('numField');
    var gcashNumber = document.getElementById('gcashNumber');
    var errGcashNumber = document.getElementById('errGcashNumber');
    var drop = document.getElementById('gcashDrop');
    var qrUpload = document.getElementById('gcashQrUpload');
    var dropTitle = document.getElementById('dropTitle');
    var qrPreview = document.getElementById('gcashQrPreview');
    var qrEmpty = document.getElementById('qrEmptyState');
    var qrStatus = document.getElementById('qrStatus');
    var errGcashQr = document.getElementById('errGcashQr');
    var MAX_BYTES = {{ \App\Support\BranchGcash::QR_MAX_KB }} * 1024;
    var TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    var saved = {
        src: qrPreview.getAttribute('src'),
        title: dropTitle.textContent,
        status: qrStatus.textContent,
        ready: qrStatus.classList.contains('is-ready')
    };

    // What follows +63: up to 10 digits, shown 3-3-4. A pasted 09…, 63… or
    // +63… number is trimmed to the same 10.
    function normalizeNumber(value) {
        var d = value.replace(/\D/g, '');
        if (d.indexOf('63') === 0 && d.length >= 12) { d = d.slice(2); }
        if (d.charAt(0) === '0') { d = d.slice(1); }
        d = d.slice(0, 10);
        var out = d.slice(0, 3);
        if (d.length > 3) { out += ' ' + d.slice(3, 6); }
        if (d.length > 6) { out += ' ' + d.slice(6, 10); }
        return out;
    }

    gcashNumber.value = normalizeNumber(gcashNumber.value);
    gcashNumber.addEventListener('input', function () {
        gcashNumber.value = normalizeNumber(gcashNumber.value);
        numField.classList.remove('is-invalid');
        errGcashNumber.hidden = true;
    });

    function showPreview(src, title, status, ready) {
        if (src) {
            qrPreview.src = src;
            qrPreview.hidden = false;
            qrEmpty.hidden = true;
        } else {
            qrPreview.removeAttribute('src');
            qrPreview.hidden = true;
            qrEmpty.hidden = false;
        }
        dropTitle.textContent = title;
        qrStatus.textContent = status;
        qrStatus.classList.toggle('is-ready', ready);
    }

    function qrError(message) {
        errGcashQr.textContent = message;
        errGcashQr.hidden = false;
        drop.classList.add('is-invalid');
    }

    function handleFile(file) {
        errGcashQr.hidden = true;
        drop.classList.remove('is-invalid');
        if (!file) {
            showPreview(saved.src, saved.title, saved.status, saved.ready);
            return false;
        }
        if (TYPES.indexOf(file.type) === -1) {
            qrError('Please choose a PNG, JPG, or WEBP image.');
            return false;
        }
        if (file.size > MAX_BYTES) {
            qrError('The QR image must be 5 MB or smaller.');
            return false;
        }
        var reader = new FileReader();
        reader.onload = function () {
            showPreview(reader.result, file.name, '● Not saved yet', false);
        };
        reader.readAsDataURL(file);
        return true;
    }

    function openPicker() {
        if (!fieldset.disabled) { qrUpload.click(); }
    }

    drop.addEventListener('click', openPicker);
    drop.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openPicker();
        }
    });
    qrUpload.addEventListener('change', function () {
        if (!handleFile(qrUpload.files && qrUpload.files[0])) {
            qrUpload.value = '';
            if (!errGcashQr.hidden) { showPreview(saved.src, saved.title, saved.status, saved.ready); }
        }
    });
    ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (event) {
            event.preventDefault();
            if (!fieldset.disabled) { drop.classList.add('is-dragging'); }
        });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        drop.addEventListener(type, function (event) {
            event.preventDefault();
            drop.classList.remove('is-dragging');
        });
    });
    drop.addEventListener('drop', function (event) {
        if (fieldset.disabled || !event.dataTransfer || !event.dataTransfer.files.length) { return; }
        var files = event.dataTransfer.files;
        if (handleFile(files[0])) {
            // Put the dropped file in the real input so the form posts it.
            try { qrUpload.files = files; } catch (e) { /* older browsers: click to upload still works */ }
        } else {
            showPreview(saved.src, saved.title, saved.status, saved.ready);
        }
    });

    gcashForm.addEventListener('submit', function (event) {
        var digits = gcashNumber.value.replace(/\D/g, '');
        var bad = digits.length > 0 && !(digits.length === 10 && digits.charAt(0) === '9');
        numField.classList.toggle('is-invalid', bad);
        errGcashNumber.hidden = !bad;
        if (bad || !errGcashQr.hidden) { event.preventDefault(); }
    });
})();
</script>

@endsection

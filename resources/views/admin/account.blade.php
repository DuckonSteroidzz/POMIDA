@extends('admin.layout')

@section('title', 'Account Settings - Peachy Admin')

@section('content')

{{--
    Account Settings — redesigned to docs/design-refs/admin-account-settings.html.

    WHO SEES THIS PAGE: the owner (role `admin`) only. The route group in
    routes/web.php is `role:admin`, so a supervisor or staff member is refused
    before this view renders; the checks below are a second lock, not the
    first. Nothing about who may see or save each card changed in the redesign.

    The Name / Email / Access Level are read-only text. There has never been a
    route that edits an admin's own name, email or role, so the redesign keeps
    them as plain readonly fields (see the long note in git history on the
    "Signed in as" block).
--}}

@php
    $adminUser = Auth::guard('admin')->user();

    $accountName  = trim((string) ($adminUser->name ?? ''));
    $accountEmail = (string) ($adminUser->email ?? '');
    $accountRole  = (string) ($adminUser->role ?? 'admin');

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

    // The Staff Accounts page is open to the manager tier (owner + supervisor).
    $canOpenStaffAccounts = in_array($accountRole, \App\Models\User::MANAGER_ROLES, true);

    $passwordErrors = collect(['current_password', 'password'])
        ->flatMap(fn ($field) => $errors->get($field))
        ->all();

    $gcashErrors = collect(['gcash_qr', 'gcash_phone'])
        ->flatMap(fn ($field) => $errors->get($field))
        ->all();

    $hasQr = !empty($gcashQr);
@endphp

<style>
    .as-page {
        --as-red: #9d1c1c;
        --as-red-dark: #821616;
        --as-coral: #f4845f;
        --as-peach: #fff1e9;
        --as-cream: #fffaf7;
        --as-ink: #342b29;
        --as-muted: #8b7c77;
        --as-line: #f0e4de;
        --as-green: #2f855a;
        max-width: 1180px;
        margin: 0 auto;
        color: var(--as-ink);
        font-size: 13px;
    }
    .as-page *, .as-page *::before, .as-page *::after { box-sizing: border-box; }

    .as-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
    .as-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; color: var(--as-coral); text-transform: uppercase; margin: 0 0 5px; }
    .as-title { font-family: 'Fraunces', Georgia, serif; font-size: 24px; line-height: 1.25; margin: 0; color: var(--as-red); font-weight: 700; }
    .as-subtitle { color: var(--as-muted); font-size: 12px; margin: 7px 0 0; }
    .as-header-icon { width: 46px; height: 46px; border-radius: 14px; background: var(--as-peach); color: var(--as-red); display: grid; place-items: center; font-size: 20px; flex-shrink: 0; }

    .as-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(300px, .78fr); gap: 18px; align-items: start; }
    .as-stack { display: grid; gap: 18px; min-width: 0; }
    .as-card { background: #fff; border: 1px solid var(--as-line); border-radius: 15px; padding: 21px 22px; box-shadow: 0 5px 18px rgba(110, 48, 30, .045); min-width: 0; }
    .as-card--soft { background: var(--as-cream); }
    .as-card-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
    .as-card-head--tight { margin-bottom: 9px; }
    .as-section-icon { width: 38px; height: 38px; border-radius: 11px; background: var(--as-peach); color: var(--as-red); display: grid; place-items: center; font-size: 17px; flex-shrink: 0; }
    .as-card-head h2 { font-size: 14px; margin: 1px 0 3px; font-weight: 700; color: var(--as-ink); }
    .as-card-head p { font-size: 11px; color: var(--as-muted); margin: 0; line-height: 1.6; }

    .as-profile { display: flex; align-items: center; gap: 14px; padding: 15px; background: var(--as-cream); border: 1px solid #f4e8e1; border-radius: 12px; margin-bottom: 17px; }
    .as-profile-text { min-width: 0; }
    .as-avatar { width: 48px; height: 48px; border-radius: 50%; background: var(--as-red); color: #fff; display: grid; place-items: center; font-size: 17px; font-weight: 700; flex-shrink: 0; }
    .as-profile-name { font-weight: 700; font-size: 13px; margin: 0 0 3px; overflow-wrap: anywhere; }
    .as-profile-email { font-size: 11px; color: var(--as-muted); margin: 0; overflow-wrap: anywhere; }
    .as-role { margin-left: auto; background: #fff0e9; color: #bd5638; padding: 5px 9px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; }

    .as-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 13px; }
    .as-field--full { grid-column: 1 / -1; }
    .as-label { display: block; font-size: 10px; font-weight: 600; color: #66534d; margin: 0 0 6px; }
    .as-input { width: 100%; height: 40px; border: 1px solid #e9dcd6; border-radius: 8px; padding: 0 12px; color: var(--as-ink); background: #fff; outline: none; font: inherit; font-size: 11px; transition: .2s; margin: 0; }
    .as-input:focus { border-color: var(--as-coral); box-shadow: 0 0 0 3px rgba(244, 132, 95, .12); }
    .as-input[readonly] { background: #f8f5f3; color: #746762; }
    /* The password inputs carry `readonly` until focused (autofill defence), so
       they must not look disabled. */
    .as-password .as-input[readonly] { background: #fff; color: var(--as-ink); }
    .as-helper { font-size: 10px; color: #a0928c; margin: 7px 0 0; line-height: 1.6; }

    .as-password { display: grid; grid-template-columns: 1fr; gap: 13px; }
    .as-password .pw-field > input { padding-right: 42px; }
    .as-password .pw-field > .js-pw-toggle { right: 8px; color: #9b8982; font-size: 15px; }

    .as-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 17px; }
    .as-btn { border: 0; border-radius: 8px; padding: 10px 16px; font: inherit; font-size: 11px; font-weight: 600; cursor: pointer; transition: .2s; }
    .as-btn-primary { background: var(--as-red); color: #fff; }
    .as-btn-primary:hover { background: var(--as-red-dark); }
    .as-btn-light { background: #fff2eb; color: var(--as-red); }
    .as-btn-light:hover { background: #ffe5d8; }
    .as-btn:focus-visible { outline: 2px solid var(--as-red); outline-offset: 2px; }

    .as-staff-link { margin-top: 15px; padding: 12px 13px; border: 1px dashed #eac9ba; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--as-cream); }
    .as-staff-link strong { display: block; font-size: 11px; margin-bottom: 3px; }
    .as-staff-link span { font-size: 10px; color: var(--as-muted); }
    .as-staff-link a { color: var(--as-red); font-size: 10px; font-weight: 700; text-decoration: none; white-space: nowrap; }
    .as-staff-link a:hover { text-decoration: underline; }

    .as-gcash-grid { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 17px; align-items: start; }
    .as-qr-preview { border: 1px solid var(--as-line); border-radius: 11px; background: var(--as-cream); padding: 12px; text-align: center; }
    .as-qr-preview small { display: block; font-size: 9px; font-weight: 700; color: var(--as-muted); letter-spacing: .7px; margin-bottom: 9px; }
    .as-qr-placeholder { width: 100%; aspect-ratio: 1; background: #fff; border: 1px dashed #e4cfc5; border-radius: 8px; display: grid; place-items: center; color: #c8a99b; font-size: 32px; overflow: hidden; position: relative; }
    /* Absolutely placed so a tall QR is letterboxed inside the square instead of overflowing and being clipped. */
    .as-qr-placeholder img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; border-radius: 7px; }
    .as-qr-empty { font-size: 10px; color: #a0928c; margin: 8px 0 0; }
    .as-status { display: inline-flex; align-items: center; gap: 6px; color: var(--as-green); font-size: 10px; font-weight: 600; margin-top: 10px; }
    .as-status i { font-size: 12px; }
    .as-status--pending { color: #bd5638; }
    .as-upload { border: 1px dashed #e5cfc4; background: var(--as-cream); border-radius: 9px; padding: 12px; }
    .as-upload input { width: 100%; font-size: 10px; color: var(--as-muted); }
    .as-upload-note { font-size: 9px; color: #a0928c; margin: 7px 0 0; }
    .as-gcash-phone { margin-top: 17px; }

    .as-notice { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 15px; padding: 10px 12px; border-radius: 8px; font-size: 11px; line-height: 1.5; }
    .as-notice i { margin-top: 1px; }
    .as-notice--ok { background: #edf8f0; color: #286b43; }
    .as-notice--error { background: #fdecec; color: #8f2424; }
    .as-notice--error div + div { margin-top: 2px; }

    @media (max-width: 850px) {
        .as-layout { grid-template-columns: 1fr; }
        .as-stack { gap: 16px; }
    }
    @media (max-width: 540px) {
        .as-card { padding: 17px; }
        .as-fields { grid-template-columns: 1fr; }
        .as-field--full { grid-column: auto; }
        .as-gcash-grid { grid-template-columns: 1fr; }
        .as-qr-preview { max-width: 190px; }
        .as-heading { margin-bottom: 17px; }
        .as-title { font-size: 21px; }
        .as-staff-link { align-items: flex-start; flex-direction: column; }
        .as-actions { flex-wrap: wrap; }
        .as-profile { flex-wrap: wrap; }
        .as-role { margin-left: 0; }
    }
</style>

<div class="as-page">

    <header class="as-heading">
        <div>
            <p class="as-eyebrow">Administration</p>
            <h1 class="as-title">Account Settings</h1>
            <p class="as-subtitle">Manage your account security and store payment details.</p>
        </div>
        <div class="as-header-icon" aria-hidden="true"><i class="bi bi-person-gear"></i></div>
    </header>

    <div class="as-layout">

        {{-- ═════════════ LEFT COLUMN ═════════════ --}}
        <div class="as-stack">

            {{-- ── Signed-in Account ── --}}
            <section class="as-card">
                <div class="as-card-head">
                    <div class="as-section-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></div>
                    <div>
                        <h2>Signed-in Account</h2>
                        <p>Your account details and assigned access level.</p>
                    </div>
                </div>

                <div class="as-profile">
                    <div class="as-avatar" aria-hidden="true">{{ $initials }}</div>
                    <div class="as-profile-text">
                        <p class="as-profile-name">{{ $accountName !== '' ? $accountName : $accountEmail }}</p>
                        <p class="as-profile-email">{{ $accountEmail }}</p>
                    </div>
                    <span class="as-role">{{ $roleLabel }}</span>
                </div>

                <div class="as-fields">
                    <div>
                        <label class="as-label" for="as_name">Name</label>
                        <input class="as-input" id="as_name" type="text" value="{{ $accountName }}" readonly autocomplete="off">
                    </div>
                    <div>
                        <label class="as-label" for="as_email">Email Address</label>
                        <input class="as-input" id="as_email" type="email" value="{{ $accountEmail }}" readonly autocomplete="off">
                    </div>
                    <div class="as-field--full">
                        <label class="as-label" for="as_access">Access Level</label>
                        <input class="as-input" id="as_access" type="text" value="{{ $roleLabel }}" readonly autocomplete="off">
                    </div>
                </div>

                <p class="as-helper"><i class="bi bi-info-circle"></i> These details are managed by an administrator and cannot be edited here.</p>

                @if($canOpenStaffAccounts)
                    <div class="as-staff-link">
                        <div>
                            <strong>Managing staff accounts?</strong>
                            <span>Add users, update access levels, or activate and deactivate accounts.</span>
                        </div>
                        <a href="{{ route('admin.users') }}">Go to Staff Accounts <i class="bi bi-arrow-up-right"></i></a>
                    </div>
                @endif
            </section>

            {{-- ── Change Password ──
                 Always the SIGNED-IN account: the form carries no user id, and
                 AdminController::updateOwnPassword() reads the account from the
                 admin guard. Field names, ids and the autofill defences are
                 unchanged from the previous page (AdminFormHygieneTest pins
                 them). --}}
            <section class="as-card" id="change-password">
                <div class="as-card-head">
                    <div class="as-section-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
                    <div>
                        <h2>Change Password</h2>
                        <p>Update the password for your signed-in account. You will stay signed in on this device.</p>
                    </div>
                </div>

                @if(session('password_success'))
                    <div class="as-notice as-notice--ok" role="status">
                        <i class="bi bi-check-circle"></i>
                        <span>{{ session('password_success') }}</span>
                    </div>
                @endif

                @if(!empty($passwordErrors))
                    <div class="as-notice as-notice--error" role="alert">
                        <i class="bi bi-exclamation-circle"></i>
                        <div>
                            @foreach($passwordErrors as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <form action="{{ route('admin.account.password.update') }}" method="POST" autocomplete="off">
                    <input type="text" name="prevent_autofill_username" id="prevent_autofill_username" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
                    <input type="password" name="prevent_autofill_password" id="prevent_autofill_password" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
                    @csrf @method('PUT')

                    <div class="as-password">
                        <div>
                            <label class="as-label" for="current_password">Current Password</label>
                            <div class="pw-field">
                                <input id="current_password" type="password" name="current_password" class="as-input"
                                       placeholder="Enter current password"
                                       readonly onfocus="this.removeAttribute('readonly');"
                                       autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" required>
                                <button type="button" class="js-pw-toggle" data-target="current_password"
                                    aria-label="Show password" aria-pressed="false" title="Show password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="as-label" for="new_password">New Password</label>
                            <div class="pw-field">
                                <input id="new_password" type="password" name="password" class="as-input"
                                       placeholder="Enter new password"
                                       readonly onfocus="this.removeAttribute('readonly');"
                                       autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" minlength="8" maxlength="20" required>
                                <button type="button" class="js-pw-toggle" data-target="new_password"
                                    aria-label="Show password" aria-pressed="false" title="Show password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="as-label" for="new_password_confirmation">Confirm New Password</label>
                            <div class="pw-field">
                                <input id="new_password_confirmation" type="password" name="password_confirmation" class="as-input"
                                       placeholder="Re-enter new password"
                                       readonly onfocus="this.removeAttribute('readonly');"
                                       autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" minlength="8" maxlength="20" required>
                                <button type="button" class="js-pw-toggle" data-target="new_password_confirmation"
                                    aria-label="Show password" aria-pressed="false" title="Show password">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <p class="as-helper">
                        {{ \App\Support\PasswordPolicy::describe() }} Your new password must be different from your current password.
                    </p>

                    <div class="as-actions">
                        <button type="reset" class="as-btn as-btn-light">Clear</button>
                        <button type="submit" class="as-btn as-btn-primary">Update Password <i class="bi bi-arrow-right"></i></button>
                    </div>
                </form>
            </section>
        </div>

        {{-- ═════════════ RIGHT COLUMN ═════════════ --}}
        <div class="as-stack">

            {{-- ── GCash Payment Settings (owner only) ── --}}
            @if($accountRole === 'admin')
                <section class="as-card" id="gcash-settings">
                    <div class="as-card-head">
                        <div class="as-section-icon" aria-hidden="true"><i class="bi bi-qr-code"></i></div>
                        <div>
                            <h2>GCash Payment Settings</h2>
                            <p>Manage the QR code and contact number shown to customers paying through GCash.</p>
                        </div>
                    </div>

                    @if(session('success'))
                        <div class="as-notice as-notice--ok" role="status">
                            <i class="bi bi-check-circle"></i>
                            <span>{{ session('success') }}</span>
                        </div>
                    @endif

                    @if(!empty($gcashErrors))
                        <div class="as-notice as-notice--error" role="alert">
                            <i class="bi bi-exclamation-circle"></i>
                            <div>
                                @foreach($gcashErrors as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="as-notice as-notice--error" id="gcash-client-error" role="alert" hidden>
                        <i class="bi bi-exclamation-circle"></i>
                        <div id="gcash-client-error-text"></div>
                    </div>

                    <form action="{{ route('admin.account.gcash-qr.update') }}" method="POST" enctype="multipart/form-data">
                        @csrf

                        <div class="as-gcash-grid">
                            <div class="as-qr-preview">
                                <small id="qrPreviewLabel">CURRENT QR CODE</small>
                                <div class="as-qr-placeholder" id="qrPreview">
                                    @if($hasQr)
                                        <img src="{{ \App\Support\Img::url($gcashQr) }}" alt="Store GCash QR Code">
                                    @else
                                        <i class="bi bi-qr-code" aria-hidden="true"></i>
                                    @endif
                                </div>
                                @if($hasQr)
                                    <span class="as-status" id="qrStatus"><i class="bi bi-check-circle-fill"></i> Saved</span>
                                @else
                                    <p class="as-qr-empty" id="qrStatus">No GCash QR uploaded</p>
                                @endif
                            </div>

                            <div>
                                <label class="as-label" for="qrUpload">{{ $hasQr ? 'Change GCash QR' : 'Upload GCash QR' }}</label>
                                <div class="as-upload">
                                    <input type="file" id="qrUpload" name="gcash_qr"
                                           accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                    <p class="as-upload-note">JPG, JPEG, PNG, or WEBP · Maximum 5 MB</p>
                                </div>

                                <div class="as-gcash-phone">
                                    <label class="as-label" for="gcashPhone">GCash Phone Number</label>
                                    <input class="as-input" id="gcashPhone" type="tel" name="gcash_phone"
                                           value="{{ old('gcash_phone', $gcashPhone ?? '') }}"
                                           placeholder="09XX XXX XXXX" maxlength="20" autocomplete="off">
                                    <p class="as-helper">Displayed below the GCash QR code during checkout.</p>
                                </div>

                                <div class="as-actions">
                                    <button type="submit" class="as-btn as-btn-primary"><i class="bi bi-cloud-arrow-up"></i> Save GCash Settings</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </section>
            @endif

            {{-- ── At a glance ── --}}
            <section class="as-card as-card--soft">
                <div class="as-card-head as-card-head--tight">
                    <div class="as-section-icon" aria-hidden="true"><i class="bi bi-lightbulb"></i></div>
                    <div>
                        <h2>Account Settings at a Glance</h2>
                        <p>Keep this page focused on your own account and store payment configuration. Staff management stays in the Staff Accounts tab.</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

@if($accountRole === 'admin')
<script>
(function () {
    'use strict';

    var input = document.getElementById('qrUpload');
    if (!input) { return; }

    var preview = document.getElementById('qrPreview');
    var label   = document.getElementById('qrPreviewLabel');
    var status  = document.getElementById('qrStatus');
    var errBox  = document.getElementById('gcash-client-error');
    var errText = document.getElementById('gcash-client-error-text');

    // Remember what the server rendered so choosing and then clearing a file
    // puts the saved QR back.
    var savedPreview = preview.innerHTML;
    var savedStatus  = status ? status.outerHTML : '';
    var savedLabel   = label.textContent;
    var MAX_BYTES    = 5 * 1024 * 1024;
    var TYPES        = ['image/jpeg', 'image/png', 'image/webp'];

    function showError(message) {
        errText.textContent = message;
        errBox.hidden = false;
    }

    function restore() {
        preview.innerHTML = savedPreview;
        label.textContent = savedLabel;
        var current = document.getElementById('qrStatus');
        if (current && savedStatus) { current.outerHTML = savedStatus; }
    }

    input.addEventListener('change', function (event) {
        errBox.hidden = true;
        var file = event.target.files[0];

        if (!file) { restore(); return; }

        if (TYPES.indexOf(file.type) === -1) {
            showError('Please choose a JPG, JPEG, PNG, or WEBP image.');
            event.target.value = '';
            restore();
            return;
        }

        if (file.size > MAX_BYTES) {
            showError('The selected image exceeds the 5 MB limit.');
            event.target.value = '';
            restore();
            return;
        }

        var reader = new FileReader();
        reader.onload = function (e) {
            var img = document.createElement('img');
            img.alt = 'Selected GCash QR preview';
            img.src = e.target.result;
            preview.innerHTML = '';
            preview.appendChild(img);
            label.textContent = 'NEW QR PREVIEW';
            var current = document.getElementById('qrStatus');
            if (current) {
                current.outerHTML = '<span class="as-status as-status--pending" id="qrStatus"><i class="bi bi-info-circle-fill"></i> Not saved yet</span>';
            }
        };
        reader.readAsDataURL(file);
    });
})();
</script>
@endif

@endsection

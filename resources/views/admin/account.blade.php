@extends('admin.layout')

@section('title', 'Account - Peachy Admin')

@section('content')

@php
    $adminUser = Auth::guard('admin')->user();
@endphp

<p class="page-title">Account Settings</p>

{{-- ── Account Info ── --}}
<div class="content-card" style="margin-bottom: 1rem;">

    {{-- ── Who you are signed in as ──
         These three are shown, not edited. There is no route that changes an
         admin's own name, email or role: the Save button that used to sit under
         them posted to AdminController::updateAccount(), whose whole body was a
         redirect back with "Account updated successfully." It saved nothing,
         which is why the owner could not tell what it applied to. Rendering the
         values as text says what is true instead of offering an edit that never
         happened. --}}
    <p style="font-size:0.9rem; font-weight:700; color:#333; margin-bottom:0.15rem;">
        <i class="bi bi-person-badge"></i> Signed in as
    </p>
    <p style="font-size:0.75rem; color:#888; margin-bottom:1rem;">
        Ask an administrator if any of these need to change.
    </p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.75rem; margin-bottom:0.5rem;">
        <div>
            <label class="form-label-custom">Name</label>
            <input type="text" class="form-control-custom" value="{{ $adminUser->name ?? '' }}" readonly autocomplete="off" style="margin-bottom:0; background:#f0f0f0;">
        </div>
        <div>
            <label class="form-label-custom">Email</label>
            <input type="email" class="form-control-custom" value="{{ $adminUser->email ?? '' }}" readonly autocomplete="off" style="margin-bottom:0; background:#f0f0f0;">
        </div>
        <div>
            <label class="form-label-custom">Access Level</label>
            <input type="text" class="form-control-custom" value="{{ $adminUser->role ?? 'admin' }}" readonly autocomplete="off" style="margin-bottom:0; background:#f0f0f0;">
        </div>
    </div>

    <div>

        {{-- Staff Table - Admin Only --}}
        @if($adminUser && $adminUser->role === 'admin')
        <div style="margin-bottom:1rem; overflow-x:auto;">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Access Level</th>
                    </tr>
                </thead>
                <tbody>
                    @if(isset($staffList) && count($staffList) > 0)
                    @foreach($staffList as $staff)
                    <tr>
                        <td>{{ $staff->name }}</td>
                        <td style="font-size:0.75rem; color:#888;">{{ $staff->email }}</td>
                        <td>
                            <span style="background:#F4845F; color:white; padding:0.2rem 0.6rem; border-radius:4px; font-size:0.7rem; font-weight:600;">
                                {{ $staff->role }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                    @else
                    <tr>
                        <td colspan="3" style="text-align:center; color:#aaa; padding:1rem;">No staff yet</td>
                    </tr>
                    @endif
                </tbody>
            </table>

            <p style="font-size:0.75rem; color:#888; margin-top:0.5rem;">
                <i class="bi bi-info-circle"></i>
                To add, activate or deactivate staff, go to
                <a href="{{ route('admin.users') }}" style="color:#C0392B; font-weight:600;">Staff Accounts</a>.
            </p>
        </div>
        @endif

    </div>

    {{-- The mid-page "Log Out" button that used to sit here is gone. It was a
         second copy of the one already in the sidebar, on every page, and gave
         no reason to prefer it. --}}
</div>

{{-- ══════════ CHANGE PASSWORD ══════════
     Replaces the old dead <button type="button">Change Password</button>, which
     had no handler, no modal and no route behind it, and the password box beside
     it that posted to a controller stub that saved nothing.

     Always the SIGNED-IN account: the form carries no user id, and
     AdminController::updateOwnPassword() reads the account from the admin guard,
     so neither an admin nor a staff member can change anyone else's password
     from here. --}}
<div class="content-card" style="margin-bottom:1rem;">

    <p style="font-size:0.9rem; font-weight:700; color:#333; margin-bottom:0.15rem;">
        <i class="bi bi-key"></i> Change Password
    </p>

    <p style="font-size:0.75rem; color:#888; margin-bottom:1rem;">
        Changes the password for <strong>{{ $adminUser->email ?? '' }}</strong> only.
        You will stay signed in on this device.
    </p>

    @if(session('password_success'))
        <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:8px; padding:0.65rem 0.85rem; font-size:0.78rem; margin-bottom:1rem;">
            <i class="bi bi-check-circle"></i>
            {{ session('password_success') }}
        </div>
    @endif

    @php
        $passwordErrors = collect(['current_password', 'password'])
            ->flatMap(fn ($field) => $errors->get($field))
            ->all();
    @endphp

    @if(!empty($passwordErrors))
        <div style="background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:8px; padding:0.65rem 0.85rem; font-size:0.78rem; margin-bottom:1rem;">
            @foreach($passwordErrors as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form action="{{ route('admin.account.password.update') }}" method="POST" autocomplete="off">
        <input type="text" name="prevent_autofill_username" id="prevent_autofill_username" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
        <input type="password" name="prevent_autofill_password" id="prevent_autofill_password" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
        @csrf @method('PUT')

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:0.75rem; margin-bottom:0.85rem;">
            <div>
                <label class="form-label-custom" for="current_password">Current password</label>
                <div class="pw-field">
                    <input id="current_password" type="password" name="current_password" class="form-control-custom"
                           readonly onfocus="this.removeAttribute('readonly');"
                           autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" required style="margin-bottom:0;">
                    <button type="button" class="js-pw-toggle" data-target="current_password"
                        aria-label="Show password" aria-pressed="false" title="Show password">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div>
                <label class="form-label-custom" for="new_password">New password</label>
                <div class="pw-field">
                    <input id="new_password" type="password" name="password" class="form-control-custom"
                           readonly onfocus="this.removeAttribute('readonly');"
                           autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" minlength="8" required style="margin-bottom:0;">
                    <button type="button" class="js-pw-toggle" data-target="new_password"
                        aria-label="Show password" aria-pressed="false" title="Show password">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div>
                <label class="form-label-custom" for="new_password_confirmation">Confirm new password</label>
                <div class="pw-field">
                    <input id="new_password_confirmation" type="password" name="password_confirmation" class="form-control-custom"
                           readonly onfocus="this.removeAttribute('readonly');"
                           autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" minlength="8" required style="margin-bottom:0;">
                    <button type="button" class="js-pw-toggle" data-target="new_password_confirmation"
                        aria-label="Show password" aria-pressed="false" title="Show password">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>

        <p style="font-size:0.72rem; color:#999; margin-bottom:1rem;">
            {{ \App\Support\PasswordPolicy::describe() }} It must also be different from your current password.
        </p>

        <button type="submit" class="btn-primary-custom" style="padding:0.6rem 1.75rem;">
            Update Password
        </button>
    </form>
</div>

{{-- ══════════ CUSTOMIZATION (Admin Only) ══════════ --}}
{{-- Hidden: admin decided not to use/expose this section. Routes/controller methods
     (updateCustomization, updateCustomerCustomization) and Setting columns are left
     intact so this can be re-enabled later by removing this comment wrapper. --}}
@if($adminUser && $adminUser->role === 'admin')
@if(false)

<div class="content-card" style="margin-bottom:1rem;">

    <p style="font-size:1rem; font-weight:700; color:white; margin-bottom:1.25rem; text-transform:uppercase; letter-spacing:0.5px; background:#C0392B; padding:0.6rem 1rem; border-radius:8px; text-align:center;">
        Customization
    </p>

    {{-- ═══ Staff Interface ═══ --}}
    <div style="margin-bottom:1.5rem;">
        <p style="font-size:0.92rem; font-weight:700; color:#333; margin-bottom:1rem; padding-bottom:0.5rem; border-bottom:2px solid #F4845F;">
            Staff Interface
        </p>

        <form action="{{ route('admin.customization.update') }}" method="POST" enctype="multipart/form-data">
            @csrf

            {{-- Logo Upload --}}
            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.85rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Upload Logo Image</label>
                <input type="file" name="logo" accept="image/*" class="form-control-custom" style="margin-bottom:0; padding:0.4rem 0.75rem;">
            </div>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.85rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Business Title</label>
                <input type="text" name="business_name" class="form-control-custom" autocomplete="off" value="{{ $settings->business_name ?? 'Peachy' }}" style="margin-bottom:0;">
            </div>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Business Tagline</label>
                <input type="text" name="business_tagline" class="form-control-custom" autocomplete="off" value="{{ $settings->business_tagline ?? 'Cakes and Deli Cafe' }}" style="margin-bottom:0;">
            </div>

            <p style="font-size:0.78rem; color:#555; margin-bottom:0.75rem; font-weight:700; text-transform:uppercase;">Background Layout Color Theme</p>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.6rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Background Color</label>
                <input type="color" name="staff_bg_color" value="{{ $settings->staff_bg_color ?? '#fde8de' }}" style="width:60px; height:36px; border:1px solid #ddd; border-radius:6px; cursor:pointer; padding:2px;">
                <span style="font-size:0.72rem; color:#888;">Preview color</span>
            </div>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.6rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Buttons Color</label>
                <input type="color" name="staff_btn_color" value="{{ $settings->staff_btn_color ?? '#F4845F' }}" style="width:60px; height:36px; border:1px solid #ddd; border-radius:6px; cursor:pointer; padding:2px;">
                <span style="font-size:0.72rem; color:#888;">Preview color</span>
            </div>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Border Color</label>
                <input type="color" name="staff_border_color" value="{{ $settings->staff_border_color ?? '#C0392B' }}" style="width:60px; height:36px; border:1px solid #ddd; border-radius:6px; cursor:pointer; padding:2px;">
                <span style="font-size:0.72rem; color:#888;">Preview color</span>
            </div>

            <p style="font-size:0.78rem; color:#555; margin-bottom:0.75rem; font-weight:700; text-transform:uppercase;">Core Color Buttons</p>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:1rem;">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <label class="form-label-custom" style="margin:0; font-size:0.75rem; flex:1;">Primary Buttons</label>
                    <input type="color" name="staff_primary_color" value="{{ $settings->staff_primary_color ?? '#F4845F' }}" style="width:50px; height:32px; border:1px solid #ddd; border-radius:4px; cursor:pointer; padding:2px;">
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <label class="form-label-custom" style="margin:0; font-size:0.75rem; flex:1;">Primary Hover</label>
                    <input type="color" name="staff_hover_color" value="{{ $settings->staff_hover_color ?? '#C0392B' }}" style="width:50px; height:32px; border:1px solid #ddd; border-radius:4px; cursor:pointer; padding:2px;">
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <label class="form-label-custom" style="margin:0; font-size:0.75rem; flex:1;">Secondary Buttons</label>
                    <input type="color" name="staff_secondary_color" value="{{ $settings->staff_secondary_color ?? '#2e2e2e' }}" style="width:50px; height:32px; border:1px solid #ddd; border-radius:4px; cursor:pointer; padding:2px;">
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <label class="form-label-custom" style="margin:0; font-size:0.75rem; flex:1;">Secondary Hover</label>
                    <input type="color" name="staff_secondary_hover" value="{{ $settings->staff_secondary_hover ?? '#1a1a1a' }}" style="width:50px; height:32px; border:1px solid #ddd; border-radius:4px; cursor:pointer; padding:2px;">
                </div>
            </div>

            <div style="display:flex; gap:0.75rem;">
                <button type="button" class="btn-danger-custom" style="padding:0.6rem 1.75rem;" onclick="this.form.reset()">Cancel</button>
                <button type="submit" class="btn-primary-custom" style="padding:0.6rem 1.75rem;">Save</button>
            </div>

        </form>
    </div>

    {{-- ═══ Customer Interface ═══ --}}
    <div>
        <p style="font-size:0.92rem; font-weight:700; color:#333; margin-bottom:1rem; padding-bottom:0.5rem; border-bottom:2px solid #F4845F;">
            Customer Interface
        </p>

        <form action="{{ route('admin.customization.customer.update') }}" method="POST">
            @csrf

            <p style="font-size:0.78rem; color:#555; margin-bottom:0.75rem; font-weight:700; text-transform:uppercase;">Background Layout Color Theme</p>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.6rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Background Color</label>
                <input type="color" name="customer_bg_color" value="{{ $settings->customer_bg_color ?? '#fde8de' }}" style="width:60px; height:36px; border:1px solid #ddd; border-radius:6px; cursor:pointer; padding:2px;">
            </div>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.6rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Buttons Color</label>
                <input type="color" name="customer_btn_color" value="{{ $settings->customer_btn_color ?? '#F4845F' }}" style="width:60px; height:36px; border:1px solid #ddd; border-radius:6px; cursor:pointer; padding:2px;">
            </div>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Border Color</label>
                <input type="color" name="customer_border_color" value="{{ $settings->customer_border_color ?? '#C0392B' }}" style="width:60px; height:36px; border:1px solid #ddd; border-radius:6px; cursor:pointer; padding:2px;">
            </div>

            <p style="font-size:0.78rem; color:#555; margin-bottom:0.75rem; font-weight:700; text-transform:uppercase;">Core Color Buttons</p>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:1rem;">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <label class="form-label-custom" style="margin:0; font-size:0.75rem; flex:1;">Primary Buttons</label>
                    <input type="color" name="customer_primary_color" value="{{ $settings->customer_primary_color ?? '#F4845F' }}" style="width:50px; height:32px; border:1px solid #ddd; border-radius:4px; cursor:pointer; padding:2px;">
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <label class="form-label-custom" style="margin:0; font-size:0.75rem; flex:1;">Primary Hover</label>
                    <input type="color" name="customer_hover_color" value="{{ $settings->customer_hover_color ?? '#C0392B' }}" style="width:50px; height:32px; border:1px solid #ddd; border-radius:4px; cursor:pointer; padding:2px;">
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <label class="form-label-custom" style="margin:0; font-size:0.75rem; flex:1;">Secondary Buttons</label>
                    <input type="color" name="customer_secondary_color" value="{{ $settings->customer_secondary_color ?? '#2e2e2e' }}" style="width:50px; height:32px; border:1px solid #ddd; border-radius:4px; cursor:pointer; padding:2px;">
                </div>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <label class="form-label-custom" style="margin:0; font-size:0.75rem; flex:1;">Secondary Hover</label>
                    <input type="color" name="customer_secondary_hover" value="{{ $settings->customer_secondary_hover ?? '#1a1a1a' }}" style="width:50px; height:32px; border:1px solid #ddd; border-radius:4px; cursor:pointer; padding:2px;">
                </div>
            </div>

            <div style="display:flex; gap:0.75rem;">
                <button type="button" class="btn-danger-custom" style="padding:0.6rem 1.75rem;" onclick="this.form.reset()">Cancel</button>
                <button type="submit" class="btn-primary-custom" style="padding:0.6rem 1.75rem;">Save</button>
            </div>

        </form>
    </div>

</div>
@endif
{{-- ══════════ GCASH PAYMENT SETTINGS ══════════ --}}
@if($adminUser && $adminUser->role === 'admin')

@php
    $gcashQrSetting = \App\Models\Setting::where('key', 'gcash_qr')
        ->whereNull('branch_id')
        ->first();

    $gcashPhoneSetting = \App\Models\Setting::where('key', 'gcash_phone')
        ->whereNull('branch_id')
        ->first();
@endphp

<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:0.35rem;">
        <i class="bi bi-qr-code"></i> GCash Payment Settings
    </p>

    <p style="font-size:0.75rem;color:#888;margin-bottom:1rem;">
        This QR code will be displayed to customers who choose GCash as their payment method.
    </p>

    @if(session('success'))
        <div style="
            background:#d4edda;
            color:#155724;
            border:1px solid #c3e6cb;
            border-radius:8px;
            padding:0.65rem 0.85rem;
            font-size:0.78rem;
            margin-bottom:1rem;
        ">
            <i class="bi bi-check-circle"></i>
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="
            background:#f8d7da;
            color:#721c24;
            border:1px solid #f5c6cb;
            border-radius:8px;
            padding:0.65rem 0.85rem;
            font-size:0.78rem;
            margin-bottom:1rem;
        ">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <style>
        @media (max-width: 700px) {
            .gcash-qr-grid { grid-template-columns: 1fr !important; }
        }
    </style>

    <div class="gcash-qr-grid" style="
        display:grid;
        grid-template-columns:minmax(180px, 280px) 1fr;
        gap:1.5rem;
        align-items:start;
    ">

        {{-- Current QR --}}
        <div style="
            border:1px solid #eee;
            border-radius:12px;
            padding:1rem;
            text-align:center;
            background:#fafafa;
        ">
            <p style="
                font-size:0.72rem;
                font-weight:700;
                color:#666;
                margin-bottom:0.75rem;
            ">
                CURRENT QR CODE
            </p>

            @if($gcashQrSetting && $gcashQrSetting->value)
                <img
                    src="{{ \App\Support\Img::url($gcashQrSetting->value) }}"
                    alt="Store GCash QR Code"
                    style="
                        width:100%;
                        max-width:220px;
                        aspect-ratio:1/1;
                        object-fit:contain;
                        background:#fff;
                        border-radius:8px;
                        border:1px solid #eee;
                        padding:0.5rem;
                    "
                >
            @else
                <div style="
                    width:100%;
                    max-width:220px;
                    aspect-ratio:1/1;
                    margin:0 auto;
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    background:#f5f5f5;
                    border:2px dashed #ddd;
                    border-radius:8px;
                    color:#aaa;
                    font-size:0.75rem;
                    padding:1rem;
                ">
                    <div>
                        <i class="bi bi-qr-code" style="font-size:2.5rem;display:block;margin-bottom:0.5rem;"></i>
                        No GCash QR uploaded
                    </div>
                </div>
            @endif
        </div>

        {{-- Upload --}}
        <div>
            <form
                action="{{ route('admin.account.gcash-qr.update') }}"
                method="POST"
                enctype="multipart/form-data"
            >
                @csrf

                <label class="form-label-custom">
                    {{ $gcashQrSetting && $gcashQrSetting->value ? 'Change GCash QR Code' : 'Upload GCash QR Code' }}
                </label>

                <input
                    type="file"
                    name="gcash_qr"
                    class="form-control-custom"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                >

                <small style="
                    display:block;
                    margin-top:0.4rem;
                    margin-bottom:1rem;
                    color:#999;
                    font-size:0.7rem;
                ">
                    JPG, JPEG, PNG, or WEBP. Maximum size: 5 MB.
                </small>

                <div style="margin-bottom:1rem;">
    <label class="form-label-custom">
        GCash Phone Number
    </label>

    <input
        type="text"
        name="gcash_phone"
        class="form-control-custom"
        value="{{ $gcashPhoneSetting->value ?? '' }}"
        autocomplete="off"
        maxlength="20"
    >

    <small style="
        display:block;
        margin-top:0.4rem;
        color:#999;
        font-size:0.7rem;
    ">
        This number will be displayed below the GCash QR code.
    </small>
</div>

                <button
                    type="submit"
                    class="btn-primary-custom"
                    style="padding:0.6rem 1.25rem;"
                >
                    <i class="bi bi-cloud-arrow-up"></i>
                    {{ $gcashQrSetting && $gcashQrSetting->value ? 'Update GCash QR' : 'Upload GCash QR' }}
                </button>
            </form>
        </div>

    </div>
</div>

@endif
@endif

@endsection
@extends('admin.layout')

@section('title', 'Account - Peachy Admin')

@section('content')

<p class="page-title">Account Settings</p>

{{-- ── Account Info ── --}}
<div class="content-card" style="margin-bottom: 1rem;">

    @if(session('success'))
    <div style="background:#d4edda; color:#155724; padding:0.6rem 0.85rem; border-radius:8px; font-size:0.82rem; margin-bottom:1rem;">
        {{ session('success') }}
    </div>
    @endif

    <form action="{{ route('admin.account.update') }}" method="POST">
        @csrf @method('PUT')

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:0.85rem;">
            <div>
                <label class="form-label-custom">Email</label>
                <input type="email" name="email" class="form-control-custom" value="{{ auth()->user()->email ?? '' }}" style="margin-bottom:0;">
            </div>
            <div>
                <label class="form-label-custom">Access Level</label>
                <input type="text" class="form-control-custom" value="{{ auth()->user()->role ?? 'admin' }}" readonly style="margin-bottom:0; background:#f0f0f0;">
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr auto; gap:0.75rem; margin-bottom:0.85rem; align-items:flex-end;">
            <div>
                <label class="form-label-custom">Password</label>
                <input type="password" name="password" class="form-control-custom" placeholder="••••••••" style="margin-bottom:0;">
            </div>
            <button type="button" style="background:#F4845F; color:white; border:none; border-radius:6px; padding:0.55rem 1rem; font-size:0.78rem; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif; white-space:nowrap;">
                Change Password
            </button>
        </div>

        {{-- Staff Table - Admin Only --}}
        @if(auth()->check() && auth()->user()->role === 'admin')
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
        </div>
        @endif

        <div style="display:flex; gap:0.75rem;">
            <button type="submit" class="btn-primary-custom" style="padding:0.6rem 1.75rem;">Save</button>
        </div>

    </form>

    {{-- Logout (separate form) --}}
    <form action="{{ route('admin.logout') }}" method="POST" style="margin-top:1rem;">
        @csrf
        <button type="submit" style="background:#C0392B; color:white; border:none; border-radius:6px; padding:0.6rem 1.75rem; font-size:0.82rem; font-weight:600; cursor:pointer; font-family:'Poppins',sans-serif;">
            Log Out
        </button>
    </form>
</div>

{{-- ══════════ CUSTOMIZATION (Admin Only) ══════════ --}}
@if(auth()->check() && auth()->user()->role === 'admin')

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
                <input type="text" name="business_name" class="form-control-custom" placeholder="Business Name" value="{{ $settings->business_name ?? 'Peachy' }}" style="margin-bottom:0;">
            </div>

            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
                <label class="form-label-custom" style="width:160px; flex-shrink:0; margin-bottom:0;">Business Tagline</label>
                <input type="text" name="business_tagline" class="form-control-custom" placeholder="Tagline" value="{{ $settings->business_tagline ?? 'Cakes and Deli Cafe' }}" style="margin-bottom:0;">
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

@endsection
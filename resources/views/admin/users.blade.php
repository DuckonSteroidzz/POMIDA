@extends('admin.layout')

@section('title', 'Staff Accounts - Peachy Admin')

@section('content')

<p class="page-title">Staff Accounts</p>

@if($errors->any())
<div style="background:#f8d7da; color:#721c24; padding:0.6rem 0.85rem; border-radius:8px; font-size:0.82rem; margin-bottom:1rem;">
    @foreach($errors->all() as $error)
        <div>{{ $error }}</div>
    @endforeach
</div>
@endif

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.25rem;">

    {{-- LEFT: Create staff form --}}
    <div class="content-card">
        <p style="font-size:0.9rem; font-weight:700; color:#333; margin-bottom:0.25rem;">
            <i class="bi bi-person-plus"></i> Create Staff Account
        </p>
        <p style="font-size:0.72rem; color:#888; margin-bottom:0.85rem;">
            Only admins can create staff accounts. Role is always set to <strong>staff</strong>.
        </p>

        <form action="{{ route('admin.users.store') }}" method="POST">
            @csrf

            <label class="form-label-custom">Full Name</label>
            <input type="text" name="name" required maxlength="255"
                value="{{ old('name') }}"
                class="form-control-custom" placeholder="e.g. Maria Santos">

            <label class="form-label-custom">Email</label>
            <input type="email" name="email" required maxlength="255"
                value="{{ old('email') }}"
                class="form-control-custom" placeholder="staff@peachy.test">

            <label class="form-label-custom">Assigned Branch</label>
            <select name="branch_id" required class="form-control-custom">
                <option value="">-- Select branch --</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>
                        {{ $b->name }}
                    </option>
                @endforeach
            </select>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.6rem;">
                <div>
                    <label class="form-label-custom">Password</label>
                    <input type="password" name="password" required minlength="8"
                        class="form-control-custom" placeholder="Min. 8 characters">
                </div>
                <div>
                    <label class="form-label-custom">Confirm Password</label>
                    <input type="password" name="password_confirmation" required minlength="8"
                        class="form-control-custom" placeholder="Re-type password">
                </div>
            </div>

            <button type="submit" class="btn-primary-custom" style="width:100%; padding:0.6rem; margin-top:0.4rem;">
                <i class="bi bi-check2-circle"></i> Create Staff Account
            </button>
        </form>
    </div>

    {{-- RIGHT: Staff list --}}
    <div class="content-card">
        <p style="font-size:0.9rem; font-weight:700; color:#333; margin-bottom:0.75rem;">
            All Staff ({{ $staff->count() }})
        </p>

        @if($staff->isEmpty())
            <div style="text-align:center; color:#aaa; padding:1.5rem; font-size:0.85rem;">
                <i class="bi bi-people" style="font-size:2rem; display:block; margin-bottom:0.5rem; color:#ddd;"></i>
                No staff accounts yet. Use the form on the left to add one.
            </div>
        @else
            <table style="width:100%; font-size:0.8rem;">
                <thead>
                    <tr style="text-align:left; color:#888; font-size:0.7rem;">
                        <th style="padding:0.3rem 0.4rem;">Name</th>
                        <th style="padding:0.3rem 0.4rem;">Email</th>
                        <th style="padding:0.3rem 0.4rem;">Branch</th>
                        <th style="padding:0.3rem 0.4rem;">Status</th>
                        <th style="padding:0.3rem 0.4rem; text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($staff as $s)
                    <tr style="border-top:1px solid #f0f0f0;">
                        <td style="padding:0.4rem;">{{ $s->name }}</td>
                        <td style="padding:0.4rem; color:#666;">{{ $s->email }}</td>
                        <td style="padding:0.4rem;">{{ $s->branch->name ?? '—' }}</td>
                        <td style="padding:0.4rem;">
                            @if($s->is_active)
                                <span style="background:#d4edda; color:#155724; padding:0.15rem 0.5rem; border-radius:10px; font-size:0.7rem; font-weight:600;">Active</span>
                            @else
                                <span style="background:#f8d7da; color:#721c24; padding:0.15rem 0.5rem; border-radius:10px; font-size:0.7rem; font-weight:600;">Disabled</span>
                            @endif
                        </td>
                        <td style="padding:0.4rem; text-align:right;">
                            <form action="{{ route('admin.users.toggle', $s->id) }}" method="POST" style="margin:0; display:inline;"
                                onsubmit="return confirm('{{ $s->is_active ? 'Deactivate' : 'Reactivate' }} this staff account?')">
                                @csrf @method('PUT')
                                <button type="submit"
                                    style="background:{{ $s->is_active ? '#C0392B' : '#4CAF50' }}; color:white; border:none; border-radius:6px; padding:0.25rem 0.55rem; font-size:0.7rem; cursor:pointer;">
                                    {{ $s->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>

@endsection

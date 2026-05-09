@extends('admin.layout')

@section('title', 'Branches - Peachy Admin')

@section('content')

<p class="page-title">Branch Management</p>

@if(session('success'))
<div class="alert-success-custom">
    <i class="bi bi-check-circle"></i> {{ session('success') }}
</div>
@endif

@if($errors->any())
<div class="alert-danger-custom">
    <i class="bi bi-exclamation-circle"></i> {{ $errors->first() }}
</div>
@endif

{{-- Create Branch --}}
<div class="content-card" style="margin-bottom:1rem;">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:1rem;">
        <i class="bi bi-plus-circle"></i> Add New Branch
    </p>
    <form action="{{ route('admin.branches.store') }}" method="POST">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.75rem;margin-bottom:0.75rem;">
            <div>
                <label class="form-label-custom">Branch Name *</label>
                <input type="text" name="name" class="form-control-custom" required placeholder="e.g. Branch 2">
            </div>
            <div>
                <label class="form-label-custom">Branch Code *</label>
                <input type="text" name="code" class="form-control-custom" required placeholder="e.g. BR2" style="text-transform:uppercase;">
                <small style="font-size:0.7rem;color:#aaa;">Unique short code</small>
            </div>
            <div>
                <label class="form-label-custom">Address</label>
                <input type="text" name="address" class="form-control-custom" placeholder="123 Main St.">
            </div>
            <div>
                <label class="form-label-custom">Contact Number</label>
                <input type="text" name="contact_number" class="form-control-custom" placeholder="09XX-XXX-XXXX">
            </div>
            <div>
                <label class="form-label-custom">Email</label>
                <input type="email" name="email" class="form-control-custom" placeholder="branch@peachy.com">
            </div>
            <div>
                <label class="form-label-custom">Opening Time</label>
                <input type="time" name="opening_time" class="form-control-custom">
            </div>
            <div>
                <label class="form-label-custom">Closing Time</label>
                <input type="time" name="closing_time" class="form-control-custom">
            </div>
        </div>
        <button type="submit" class="btn-primary-custom">
            <i class="bi bi-plus-circle"></i> Add Branch
        </button>
    </form>
</div>

{{-- Branches List --}}
<div class="content-card">
    <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:1rem;">
        All Branches ({{ count($branches) }})
    </p>

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
            <thead>
                <tr style="background:#F4845F;color:white;">
                    <th style="padding:0.6rem;text-align:left;">Branch</th>
                    <th style="padding:0.6rem;text-align:left;">Code</th>
                    <th style="padding:0.6rem;text-align:left;">Address</th>
                    <th style="padding:0.6rem;text-align:center;">Contact</th>
                    <th style="padding:0.6rem;text-align:center;">Hours</th>
                    <th style="padding:0.6rem;text-align:center;">Status</th>
                    <th style="padding:0.6rem;text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($branches as $branch)
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:0.6rem;font-weight:600;color:#F4845F;">
                        {{ $branch->name }}
                        @if($branch->is_main_branch)
                        <span style="background:#F4845F;color:white;padding:0.1rem 0.4rem;border-radius:10px;font-size:0.65rem;font-weight:600;margin-left:0.3rem;">Main</span>
                        @endif
                    </td>
                    <td style="padding:0.6rem;font-weight:600;">{{ $branch->code }}</td>
                    <td style="padding:0.6rem;color:#666;font-size:0.78rem;">{{ $branch->address ?? '—' }}</td>
                    <td style="padding:0.6rem;text-align:center;font-size:0.78rem;">{{ $branch->contact_number ?? '—' }}</td>
                    <td style="padding:0.6rem;text-align:center;font-size:0.78rem;">
                        @if($branch->opening_time && $branch->closing_time)
                        {{ \Carbon\Carbon::parse($branch->opening_time)->format('g:i A') }} -
                        {{ \Carbon\Carbon::parse($branch->closing_time)->format('g:i A') }}
                        @else
                        —
                        @endif
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        <span style="background:{{ $branch->is_active ? '#4CAF50' : '#ccc' }};color:white;padding:0.15rem 0.6rem;border-radius:20px;font-size:0.7rem;font-weight:600;">
                            {{ $branch->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td style="padding:0.6rem;text-align:center;">
                        @if(!$branch->is_main_branch)
                        <form action="{{ route('admin.branches.delete', $branch->id) }}" method="POST"
                            style="display:inline;"
                            onsubmit="return confirm('Delete {{ $branch->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                style="background:#C0392B;color:white;border:none;border-radius:6px;padding:0.3rem 0.6rem;font-size:0.75rem;cursor:pointer;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        @else
                        <span style="color:#aaa;font-size:0.75rem;">Main branch</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection
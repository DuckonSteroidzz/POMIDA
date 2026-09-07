@extends('admin.layout')

@section('title', 'Branches - Peachy Admin')

@section('content')

<p class="page-title">Branch Management</p>

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
    <form action="{{ route('admin.branches.store') }}" method="POST" autocomplete="off">
        <input type="text" name="prevent_autofill_username" id="prevent_autofill_username" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
        <input type="password" name="prevent_autofill_password" id="prevent_autofill_password" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.75rem;margin-bottom:0.75rem;">
            <div>
                <label class="form-label-custom">Branch Name *</label>
                <input type="text" name="name" class="form-control-custom" required readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
            </div>
            <div>
                <label class="form-label-custom">Branch Code *</label>
                <input type="text" name="code" class="form-control-custom" required readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" style="text-transform:uppercase;">
                <small style="font-size:0.7rem;color:#aaa;">Unique short code</small>
            </div>
            <div>
                <label class="form-label-custom">Address</label>
                <input type="text" name="address" class="form-control-custom" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
            </div>
            <div>
                <label class="form-label-custom">Contact Number</label>
                <input type="text" name="contact_number" class="form-control-custom" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
            </div>
            <div>
                <label class="form-label-custom">Email</label>
                <input type="email" name="email" class="form-control-custom" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
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
<div class="bl-head no-print">
    <div class="bl-head-text">
        <p class="bl-title">Branch List</p>
        <p class="bl-sub">Search, filter and manage all branches</p>
    </div>
    <span class="bl-chip" id="blCount">{{ count($branches) }} branches</span>
</div>

<div class="bl-card bl-filter-card">
    <div class="bl-filter">
        <div class="bl-field" style="grid-column:span 2;">
            <label class="bl-label" for="blSearch">Search</label>
            <input id="blSearch" type="search" class="bl-input" aria-label="Search branches" autocomplete="off">
        </div>
        <div class="bl-field">
            <label class="bl-label" for="blStatus">Status</label>
            <select id="blStatus" class="bl-input">
                <option value="">All Statuses</option>
                <option value="open">Open</option>
                <option value="closed">Inactive</option>
            </select>
        </div>
        <div class="bl-field bl-field-actions">
            <button type="button" class="bl-btn bl-btn-ghost" onclick="blReset()">Reset</button>
        </div>
    </div>
</div>

<div class="bl-card bl-table-card">
    <table class="bl-table">
        <thead>
            <tr>
                <th class="bl-ta-left">Branch</th>
                <th class="bl-ta-left">Code</th>
                <th class="bl-ta-left">Address</th>
                <th class="bl-ta-center">Contact</th>
                <th class="bl-ta-center">Hours</th>
                <th class="bl-ta-center">Status</th>
                <th class="bl-ta-center">Actions</th>
            </tr>
        </thead>
        <tbody id="blBody">
            @forelse($branches as $branch)
            @php
                $hours = ($branch->opening_time && $branch->closing_time)
                    ? \Carbon\Carbon::parse($branch->opening_time)->format('g:i A').' – '.\Carbon\Carbon::parse($branch->closing_time)->format('g:i A')
                    : '—';
            @endphp
            <tr class="bl-row"
                data-status="{{ $branch->is_active ? 'open' : 'closed' }}"
                data-search="{{ strtolower($branch->name.' '.$branch->code.' '.$branch->address.' '.$branch->contact_number.' '.$branch->email) }}"
                data-branch-id="{{ $branch->id }}">
                <td data-label="Branch" class="bl-name">
                    <i class="bi bi-chevron-right bl-row-chevron"></i>
                    {{ $branch->name }}
                    @if($branch->is_main_branch)
                        <span class="bl-badge bl-badge-main">Main</span>
                    @endif
                </td>
                <td data-label="Code" class="bl-code">{{ $branch->code }}</td>
                <td data-label="Address" class="bl-address">{{ $branch->address ?: '—' }}</td>
                <td data-label="Contact" class="bl-ta-center bl-soft">{{ $branch->contact_number ?: '—' }}</td>
                <td data-label="Hours" class="bl-ta-center bl-soft bl-nowrap">{{ $hours }}</td>
                <td data-label="Status" class="bl-ta-center">
                    <span class="bl-badge {{ $branch->is_active ? 'bl-badge-ok' : 'bl-badge-off' }}">
                        {{ $branch->is_active ? 'Open' : 'Inactive' }}
                    </span>
                </td>
                <td data-label="Actions" class="bl-ta-center">
                    <div class="bl-actions">
                        <form action="{{ route('admin.branches.toggle', $branch->id) }}" method="POST"
                            onsubmit="return confirm('{{ $branch->is_active ? 'Set ' . $branch->name . ' as inactive? Customers will no longer be able to order from this branch.' : 'Open ' . $branch->name . ' for orders?' }}')">
                            @csrf @method('PUT')
                            <button type="submit" class="bl-btn bl-btn-sm {{ $branch->is_active ? 'bl-btn-solid' : 'bl-btn-open' }}"
                                title="{{ $branch->is_active ? 'Stop accepting orders' : 'Start accepting orders' }}">
                                <i class="bi {{ $branch->is_active ? 'bi-door-closed' : 'bi-door-open' }}"></i>
                                {{ $branch->is_active ? 'Set as Inactive' : 'Set as Open' }}
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr class="bl-empty-row">
                <td colspan="7">
                    <div class="bl-empty">
                        <i class="bi bi-shop"></i>
                        <p>No branches yet</p>
                        <span>Add your first branch using the form above.</span>
                    </div>
                </td>
            </tr>
            @endforelse
            <tr class="bl-noresult-row" id="blNoResults" hidden>
                <td colspan="7">
                    <div class="bl-empty">
                        <i class="bi bi-search"></i>
                        <p>No branches match your search</p>
                        <span>Try a different keyword or clear the filters.</span>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="bl-pager" id="blPager" hidden>
        <div class="bl-pager-info">
            <span id="blPagerRange"></span>
            <label class="bl-pager-size">
                Rows
                <select id="blPageSize" class="bl-input bl-input-sm">
                    <option value="15" selected>15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </label>
        </div>
        <div class="bl-pager-nav">
            <button type="button" class="bl-page-btn" id="blPrev" onclick="blGo(blPage - 1)">
                <i class="bi bi-chevron-left"></i> Prev
            </button>
            <span class="bl-page-list" id="blPageList"></span>
            <button type="button" class="bl-page-btn" id="blNext" onclick="blGo(blPage + 1)">
                Next <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>
</div>


{{-- Store Information / Edit Branch Details --}}
<div style="margin-top:1rem;display:grid;gap:1rem;">
    @foreach($branches as $branch)
    <div class="content-card bl-store-card" id="branch-store-{{ $branch->id }}" style="overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:1rem;">
            <div>
                <p style="font-size:0.95rem;font-weight:700;color:#333;margin:0;">
                    <i class="bi bi-shop"></i> Store Information — {{ $branch->name }}
                </p>
                <p style="font-size:0.72rem;color:#999;margin:0.25rem 0 0;">
                    Customers will see this information in More.
                </p>
            </div>
            @if($branch->is_main_branch)
                <span style="background:#F4845F;color:white;padding:0.2rem 0.55rem;border-radius:10px;font-size:0.65rem;font-weight:600;">Main</span>
            @endif
        </div>

        <form action="{{ route('admin.branches.update', $branch->id) }}" method="POST" autocomplete="off">
            <input type="text" name="prevent_autofill_username" id="prevent_autofill_username_{{ $branch->id }}" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
            <input type="password" name="prevent_autofill_password" id="prevent_autofill_password_{{ $branch->id }}" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
            @csrf
            @method('PUT')

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">
                <div>
                    <label class="form-label-custom">Business / Branch Name *</label>
                    <input type="text" name="name" class="form-control-custom" required readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" value="{{ $branch->name }}">
                </div>
                <div>
                    <label class="form-label-custom">Address</label>
                    <input type="text" name="address" class="form-control-custom" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" value="{{ $branch->address }}">
                </div>
                <div>
                    <label class="form-label-custom">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control-custom" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" value="{{ $branch->contact_number }}">
                </div>
                <div>
                    <label class="form-label-custom">Email</label>
                    <input type="email" name="email" class="form-control-custom" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" value="{{ $branch->email }}">
                </div>
                <div>
                    <label class="form-label-custom">Opening Time</label>
                    <input type="time" name="opening_time" class="form-control-custom" value="{{ $branch->opening_time }}">
                </div>
                <div>
                    <label class="form-label-custom">Closing Time</label>
                    <input type="time" name="closing_time" class="form-control-custom" value="{{ $branch->closing_time }}">
                </div>
            </div>

            <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid #f0f0f0;">
                <p style="font-size:0.85rem;font-weight:700;color:#333;margin-bottom:0.75rem;">
                    <i class="bi bi-share"></i> Social Media
                </p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">
                    <div>
                        <label class="form-label-custom">Facebook</label>
                        <input type="url" name="facebook_url" class="form-control-custom" autocomplete="off" value="{{ $branchSettings[$branch->id]['facebook_url'] ?? '' }}">
                    </div>
                    <div>
                        <label class="form-label-custom">Instagram</label>
                        <input type="url" name="instagram_url" class="form-control-custom" autocomplete="off" value="{{ $branchSettings[$branch->id]['instagram_url'] ?? '' }}">
                    </div>
                    <div>
                        <label class="form-label-custom">TikTok</label>
                        <input type="url" name="tiktok_url" class="form-control-custom" autocomplete="off" value="{{ $branchSettings[$branch->id]['tiktok_url'] ?? '' }}"><br>
                    </div>
                    <div>
                        <label class="form-label-custom">Other Social Media</label>
                        <input type="url" name="other_social_url" class="form-control-custom" autocomplete="off" value="{{ $branchSettings[$branch->id]['other_social_url'] ?? '' }}"><br>
                    </div>
                </div>
            </div>

            <div style="margin-top:1rem;display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:0.45rem;font-size:0.78rem;color:#555;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" {{ $branch->is_active ? 'checked' : '' }}>
                    Accept orders from this branch
                </label>
                <button type="submit" class="btn-primary-custom">
                    <i class="bi bi-check2"></i> Save Store Information
                </button>
            </div>
        </form>
    </div>
    @endforeach
</div>

@endsection

@push('styles')
<link href="/vendor/gfonts.css" rel="stylesheet">
<style>
    /* ── Peachy tokens (matched to Completed Orders) ── */
    .bl-head, .bl-card, .bl-pager, .bl-table { font-family: 'Karla', system-ui, sans-serif; color: #3B2A24; }

    /* ── Header ── */
    .bl-head {
        display: grid; grid-template-columns: minmax(0, 1fr);
        gap: 0.85rem; margin-bottom: 1.1rem;
    }
    .bl-head-text { min-width: 0; }
    .bl-title {
        font-family: 'Fraunces', Georgia, serif; font-weight: 700;
        font-size: 1.75rem; letter-spacing: -0.01em; margin: 0; color: #3B2A24;
    }
    .bl-sub { margin: 0.15rem 0 0; font-size: 0.82rem; color: #8B7A72; }
    .bl-chip {
        justify-self: start;
        font-size: 0.72rem; font-weight: 700; letter-spacing: 0.02em;
        color: #C0392B; background: #FDF1E6; border: 1px solid #F8D7B0;
        border-radius: 999px; padding: 0.35rem 0.7rem; white-space: nowrap;
    }

    /* ── Buttons ── */
    .bl-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 0.78rem; font-weight: 700;
        padding: 0.55rem 1rem; border-radius: 10px; border: 1px solid transparent;
        cursor: pointer; text-decoration: none; line-height: 1.1;
        transition: transform .12s ease, box-shadow .12s ease, background .12s ease;
    }
    .bl-btn:hover { transform: translateY(-1px); }
    .bl-btn-sm { font-size: 0.7rem; padding: 0.35rem 0.65rem; border-radius: 8px; }
    .bl-btn-solid {
        background: linear-gradient(135deg, #F4845F, #EF8585);
        color: #fff; box-shadow: 0 6px 14px -8px rgba(192,57,43,.6);
    }
    .bl-btn-solid:hover { background: linear-gradient(135deg, #EF8585, #C0392B); color: #fff; }
    .bl-btn-ghost { background: #fff; color: #C0392B; border-color: #F6B49B; }
    .bl-btn-ghost:hover { background: #FDF6EF; color: #C0392B; }
    .bl-btn-open { background: #E6F4EC; color: #2E7D5B; border-color: #C6E6D5; }
    .bl-btn-open:hover { background: #2E7D5B; color: #fff; border-color: transparent; }
    .bl-btn-danger { background: #FBE7E4; color: #C0392B; border-color: #F6C9C1; }
    .bl-btn-danger:hover { background: #C0392B; color: #fff; border-color: transparent; }

    /* ── Cards ── */
    .bl-card {
        background: #fff; border: 1px solid #F0E2D5; border-radius: 16px;
        box-shadow: 0 10px 30px -24px rgba(59,42,36,.45); margin-bottom: 1rem;
    }
    .bl-filter-card { padding: 1rem 1.1rem; background: linear-gradient(180deg, #FDF6EF, #fff); }
    .bl-table-card { padding: 0; overflow: hidden; overflow-x: auto; }

    /* ── Filter ── */
    .bl-filter {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0.75rem; align-items: end;
    }
    .bl-field { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; }
    .bl-field-actions { flex-direction: row; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .bl-label {
        font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.08em; color: #8B7A72;
    }
    .bl-input {
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 0.82rem; color: #3B2A24;
        background: #fff; border: 1px solid #F0E2D5; border-radius: 10px;
        padding: 0.5rem 0.6rem; width: 100%; margin: 0;
    }
    .bl-input:focus { outline: none; border-color: #F4845F; box-shadow: 0 0 0 3px rgba(244,132,95,.18); }
    .bl-input-sm { padding: 0.25rem 0.4rem; font-size: 0.75rem; width: auto; }

    /* ── Table ── */
    .bl-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.82rem; }
    .bl-table thead th {
        background: #FDF1E6; font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.07em; color: #C0392B;
        padding: 0.75rem 0.7rem; white-space: nowrap;
        border-bottom: 1px solid #F0E2D5;
    }
    .bl-table tbody td { padding: 0.7rem; border-bottom: 1px solid #F7EFE7; vertical-align: middle; }
    .bl-table tbody tr:hover td { background: #FFFBF7; }
    .bl-ta-left { text-align: left; }
    .bl-ta-center { text-align: center; }
    .bl-name { font-family: 'Fraunces', Georgia, serif; font-weight: 600; color: #C0392B; }
    .bl-code { font-weight: 700; letter-spacing: 0.04em; }
    .bl-address { font-size: 0.76rem; color: #5B4740; max-width: 260px; }
    .bl-soft { font-size: 0.76rem; color: #8B7A72; }
    .bl-nowrap { white-space: nowrap; }
    .bl-muted { color: #C4B6AE; font-size: 0.72rem; }

    .bl-badge {
        display: inline-block; font-size: 0.68rem; font-weight: 700;
        padding: 0.2rem 0.6rem; border-radius: 999px; white-space: nowrap;
    }
    .bl-badge-main { background: #F8D7B0; color: #8A4A2E; margin-left: 0.35rem; }
    .bl-badge-ok { background: #E6F4EC; color: #2E7D5B; border: 1px solid #C6E6D5; }
    .bl-badge-off { background: #FBE7E4; color: #C0392B; border: 1px solid #F6C9C1; }

    .bl-actions { display: inline-flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; justify-content: center; }
    .bl-actions form { display: inline; margin: 0; }

    .bl-empty { text-align: center; padding: 2.5rem 1rem; color: #8B7A72; }
    .bl-empty i { font-size: 1.6rem; color: #F6B49B; }
    .bl-empty p { font-family: 'Fraunces', Georgia, serif; font-size: 1rem; font-weight: 600; margin: 0.5rem 0 0.2rem; color: #3B2A24; }
    .bl-empty span { font-size: 0.78rem; }

    /* ── Pagination ── */
    .bl-pager {
        display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.7rem;
        padding: 0.8rem 1rem; border-top: 1px solid #F0E2D5; background: #FFFBF7;
    }
    .bl-pager-info {
        display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
        font-size: 0.76rem; color: #8B7A72; font-weight: 600;
    }
    .bl-pager-size { display: inline-flex; align-items: center; gap: 0.35rem; }
    .bl-pager-nav { display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; }
    .bl-page-list { display: flex; align-items: center; gap: 0.25rem; flex-wrap: wrap; }
    .bl-page-btn {
        min-width: 32px; height: 32px; padding: 0 0.55rem;
        display: inline-flex; align-items: center; gap: 0.25rem;
        background: #fff; border: 1px solid #F0E2D5; border-radius: 9px;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 0.76rem; font-weight: 700; color: #5B4740;
        cursor: pointer;
    }
    .bl-page-btn:hover:not(:disabled) { border-color: #F4845F; color: #C0392B; }
    .bl-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .bl-page-btn.is-active {
        background: linear-gradient(135deg, #F4845F, #EF8585);
        color: #fff; border-color: transparent;
    }
    .bl-page-gap { color: #C4B6AE; padding: 0 0.15rem; }
    .bl-hidden { display: none !important; }

    /* ── Expandable rows / Store Information ── */
    .bl-row { cursor: pointer; }
    .bl-row-chevron {
        display: inline-block; transition: transform .2s ease; color: #C4B6AE;
        font-size: 0.7rem; margin-right: 0.35rem;
    }
    .bl-row:hover .bl-row-chevron { color: #F4845F; }
    .bl-row.is-open .bl-row-chevron { transform: rotate(90deg); color: #F4845F; }
    .bl-store-card { display: none; }
    .bl-store-card.is-open { display: block; }

    /* ── Desktop ── */
    @media (min-width: 768px) {
        .bl-head { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
        .bl-chip { justify-self: end; }
        .bl-pager { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
        .bl-pager-nav { justify-content: flex-end; }
    }

    /* ── Mobile: rows become cards ── */
    @media (max-width: 767px) {
        .bl-title { font-size: 1.4rem; }
        .bl-table-card { border: none; background: transparent; box-shadow: none; overflow: visible; }
        .bl-table thead { display: none; }
        .bl-table, .bl-table tbody, .bl-table tr, .bl-table td { display: block; width: 100%; }
        .bl-table tbody tr.bl-row {
            background: #fff; border: 1px solid #F0E2D5; border-radius: 14px;
            box-shadow: 0 10px 26px -24px rgba(59,42,36,.5);
            padding: 0.35rem 0.15rem; margin-bottom: 0.7rem;
        }
        .bl-table tbody tr.bl-row:hover td { background: transparent; }
        .bl-table tbody td {
            display: grid; grid-template-columns: 38% minmax(0, 62%);
            gap: 0.5rem; align-items: center;
            border-bottom: 1px dashed #F7EFE7;
            padding: 0.5rem 0.85rem; text-align: left !important;
        }
        .bl-table tbody td:last-child { border-bottom: none; }
        .bl-table tbody td::before {
            content: attr(data-label);
            font-size: 0.66rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.07em; color: #8B7A72;
        }
        .bl-address { max-width: none; }
        .bl-actions { justify-content: flex-start; }
        .bl-pager { background: #fff; border: 1px solid #F0E2D5; border-radius: 14px; }
        .bl-empty-row td, .bl-noresult-row td { padding: 0; }
        .bl-empty-row td::before, .bl-noresult-row td::before { content: none; }
    }
</style>
@endpush

@push('scripts')
<script>
var blPage = 1;
var blPageSize = 15;

function blAllRows() {
    return Array.prototype.slice.call(document.querySelectorAll('#blBody tr.bl-row'));
}

function blMatches() {
    var qEl = document.getElementById('blSearch');
    var sEl = document.getElementById('blStatus');
    var q = qEl ? qEl.value.trim().toLowerCase() : '';
    var status = sEl ? sEl.value : '';

    return blAllRows().filter(function(row) {
        var okText = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
        var okStatus = !status || row.getAttribute('data-status') === status;
        return okText && okStatus;
    });
}

function blRender() {
    var all = blAllRows();
    var rows = blMatches();
    var pager = document.getElementById('blPager');
    var noResults = document.getElementById('blNoResults');
    var count = document.getElementById('blCount');

    var pages = Math.max(1, Math.ceil(rows.length / blPageSize));
    if (blPage > pages) blPage = pages;
    if (blPage < 1) blPage = 1;

    var start = (blPage - 1) * blPageSize;
    var end = Math.min(start + blPageSize, rows.length);

    all.forEach(function(row) { row.classList.add('bl-hidden'); });
    rows.slice(start, end).forEach(function(row) { row.classList.remove('bl-hidden'); });

    if (noResults) noResults.hidden = !(all.length > 0 && rows.length === 0);
    if (count) count.innerText = rows.length + (rows.length === 1 ? ' branch' : ' branches');

    if (!pager) return;
    pager.hidden = rows.length === 0;

    document.getElementById('blPagerRange').innerText =
        rows.length ? 'Showing ' + (start + 1) + '–' + end + ' of ' + rows.length : '';
    document.getElementById('blPrev').disabled = (blPage === 1);
    document.getElementById('blNext').disabled = (blPage === pages);

    var list = document.getElementById('blPageList');
    list.innerHTML = '';
    blPageNumbers(blPage, pages).forEach(function(p) {
        if (p === '…') {
            var gap = document.createElement('span');
            gap.className = 'bl-page-gap';
            gap.innerText = '…';
            list.appendChild(gap);
            return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bl-page-btn' + (p === blPage ? ' is-active' : '');
        btn.innerText = p;
        btn.onclick = function() { blGo(p); };
        list.appendChild(btn);
    });
}

function blPageNumbers(current, pages) {
    var out = [];
    for (var i = 1; i <= pages; i++) {
        if (i === 1 || i === pages || Math.abs(i - current) <= 1) {
            out.push(i);
        } else if (out[out.length - 1] !== '…') {
            out.push('…');
        }
    }
    return out;
}

function blGo(page) {
    blPage = page;
    blRender();
    var card = document.querySelector('.bl-table-card');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function blReset() {
    var q = document.getElementById('blSearch');
    var s = document.getElementById('blStatus');
    if (q) q.value = '';
    if (s) s.value = '';
    blPage = 1;
    blRender();
}

function blBindRowToggle() {
    document.querySelectorAll('#blBody tr.bl-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (e.target.closest('button, form, a, .bl-actions, input, select, textarea')) return;
            var id = row.getAttribute('data-branch-id');
            var card = document.getElementById('branch-store-' + id);
            if (!card) return;
            var opening = !card.classList.contains('is-open');
            card.classList.toggle('is-open', opening);
            row.classList.toggle('is-open', opening);
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var q = document.getElementById('blSearch');
    var s = document.getElementById('blStatus');
    var size = document.getElementById('blPageSize');

    if (q) q.addEventListener('input', function() { blPage = 1; blRender(); });
    if (s) s.addEventListener('change', function() { blPage = 1; blRender(); });
    if (size) {
        blPageSize = parseInt(size.value, 10) || 15;
        size.addEventListener('change', function() {
            blPageSize = parseInt(this.value, 10) || 15;
            blPage = 1;
            blRender();
        });
    }
    blRender();
    blBindRowToggle();
});
</script>
@endpush

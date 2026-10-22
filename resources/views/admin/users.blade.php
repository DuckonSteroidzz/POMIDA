@extends('admin.layout')

@section('title', 'Staff Accounts - Peachy Admin')

@section('content')

<style>
    /* ── Staff Accounts (Oct 2026 redesign) ──
       Two columns on a desktop, one on anything narrower. minmax(0, …) tracks,
       not bare fr: a bare 1fr takes its minimum from its content's min-content,
       and the accounts table is wider than any phone — it used to push the whole
       page sideways. */
    .su-cols {
        display: grid;
        grid-template-columns: minmax(0, 4fr) minmax(0, 7fr);
        gap: 1.25rem;
        align-items: start;
    }
    @media (max-width: 1100px) { .su-cols { grid-template-columns: minmax(0, 1fr); } }

    .su-card {
        min-width: 0;
        background: #fff;
        border: 1px solid rgba(244, 132, 95, 0.28);
        border-radius: 16px;
        padding: 1.25rem;
        box-shadow: 0 10px 30px -18px rgba(139, 26, 26, 0.45);
    }
    @media (max-width: 640px) { .su-card { padding: 1rem; } }

    .su-card-head {
        display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap;
        margin-bottom: 1rem; padding-bottom: 0.8rem;
        border-bottom: 2px dashed rgba(244, 132, 95, 0.28);
    }
    .su-card-title {
        display: flex; align-items: center; gap: 0.5rem; margin: 0;
        font-size: 1.05rem; font-weight: 700; color: #8B1A1A;
    }
    .su-card-title i { color: #C0392B; }
    .su-count {
        display: inline-block; padding: 0.05rem 0.55rem; border-radius: 999px;
        background: #FDE8DE; color: #C0392B; font-size: 0.72rem; font-weight: 700;
    }
    .su-card-sub { margin: 0 0 1rem; font-size: 0.8rem; color: #9A837B; line-height: 1.5; }
    .su-card-sub strong { color: #5A2920; }

    /* ── Create form ── */
    .su-field { margin-bottom: 0.95rem; min-width: 0; }
    .su-field .form-control-custom { margin-bottom: 0; }
    .su-field .form-control-custom[aria-invalid="true"] { border-color: #C0392B; }
    .su-pw-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem; }
    @media (max-width: 420px) { .su-pw-grid { grid-template-columns: minmax(0, 1fr); } }
    .su-err { margin: 0.3rem 0 0; font-size: 0.74rem; font-weight: 600; color: #C0392B; }
    .su-help { margin: 0 0 0.9rem; font-size: 0.74rem; color: #9A837B; line-height: 1.45; }
    .su-submit {
        width: 100%; min-height: 44px; padding: 0.7rem 1rem;
        border: 0; border-radius: 10px; cursor: pointer;
        background: linear-gradient(135deg, #F4845F 0%, #C0392B 100%);
        color: #fff; font-family: inherit; font-size: 0.9rem; font-weight: 700;
        display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem;
        transition: filter 0.18s, transform 0.12s;
    }
    .su-submit:hover { filter: brightness(1.07); transform: translateY(-1px); }
    .su-submit:focus-visible { outline: 3px solid rgba(244, 132, 95, 0.35); outline-offset: 2px; }

    .su-banner { margin-bottom: 1rem; padding: 0.6rem 0.85rem; border-radius: 8px; background: #f8d7da; color: #721c24; font-size: 0.82rem; }

    /* ── List toolbar ── */
    .su-search {
        display: flex; align-items: center; gap: 0.5rem; margin: 0;
        flex: 0 1 260px; min-width: 0; min-height: 40px; padding: 0 0.8rem;
        background: #fff; border: 1.5px solid #F6B49B; border-radius: 10px; color: #9A837B;
    }
    .su-search:focus-within { border-color: #F4845F; box-shadow: 0 0 0 3px rgba(244, 132, 95, 0.18); }
    .su-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font-size: 0.85rem; color: #333; font-family: inherit; }
    @media (max-width: 640px) { .su-search { flex: 1 1 100%; } }

    /* Background refresh: the old list stays put, dimmed, until the new one
       is swapped in. Clicks are ignored meanwhile so a stale row is never
       acted on. */
    #suResults { transition: opacity 0.15s ease; }
    #suResults.is-loading { opacity: 0.5; pointer-events: none; }
    .su-error { margin: 0 0 0.8rem; padding: 0.55rem 0.8rem; border-radius: 10px; background: #FDE2DC; color: #8B1A1A; font-size: 0.8rem; font-weight: 600; }
    .su-error[hidden] { display: none; }
    .su-error button { margin-left: 0.4rem; border: 0; background: none; padding: 0; color: #C0392B; font: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }

    /* ── Table ── */
    .su-table-wrap { max-width: 100%; border: 1px solid rgba(244, 132, 95, 0.28); border-radius: 12px; }
    #staffTable { border-collapse: collapse; }
    #staffTable thead th {
        padding: 0.7rem 0.6rem; background: #FDF1E6; color: #8B1A1A; text-align: left; white-space: nowrap;
        font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    }
    #staffTable thead th.su-th-actions { text-align: right; }
    #staffTable tbody td { padding: 0.65rem 0.6rem; vertical-align: middle; color: #4A3B36; }
    #staffTable tbody tr.su-row td { border-top: 1px solid rgba(244, 132, 95, 0.28); }
    #staffTable tbody tr.su-row:first-child td { border-top: 0; }
    #staffTable tbody tr.su-row:hover td { background: #FFFAF6; }
    #staffTable tr[hidden] { display: none; }
    /* Qualified to beat `#staffTable tbody td { color }` above (an id outranks a class). */
    #staffTable tbody td.su-name { font-weight: 700; color: #C0392B; min-width: 110px; overflow-wrap: anywhere; }
    #staffTable tbody td.su-email { color: #6b5a54; overflow-wrap: anywhere; }
    .su-actions { text-align: right; white-space: nowrap; }

    .su-badge {
        display: inline-flex; align-items: center; padding: 0.18rem 0.6rem; border-radius: 999px;
        font-size: 0.7rem; font-weight: 700; white-space: nowrap;
    }
    .su-badge.is-supervisor { background: #E7D9F5; color: #4A2C68; }
    .su-badge.is-staff { background: #E3EAF2; color: #2C3E50; }
    .su-badge.is-active { background: #E6F5EC; color: #2E8B57; }
    .su-badge.is-disabled { background: #FDECEA; color: #C0392B; }

    /* ── ⋮ button and menu ── The menu is display:none when closed, so it can
       never sit over another row's controls. position:fixed against the
       viewport so the table's own scroll box never clips it. */
    .su-kebab {
        display: inline-grid; place-items: center; width: 38px; height: 36px; padding: 0;
        border: 1px solid #F6B49B; border-radius: 10px; background: #fff; color: #8B1A1A;
        font-size: 1.25rem; font-weight: 800; line-height: 1; cursor: pointer;
        transition: background 0.15s ease, border-color 0.15s ease;
    }
    .su-kebab:hover, .su-kebab[aria-expanded="true"] { background: #FFF4EC; border-color: #F4845F; }
    .su-kebab:focus-visible { outline: 3px solid rgba(244, 132, 95, 0.25); outline-offset: 2px; }
    .su-menu {
        position: fixed; z-index: 1100; display: grid; gap: 3px; min-width: 176px; padding: 6px; text-align: left;
        background: #fff; border: 1px solid rgba(244, 132, 95, 0.28); border-radius: 12px;
        box-shadow: 0 12px 28px rgba(74, 59, 54, 0.16);
    }
    .su-menu[hidden] { display: none; }
    .su-menu form { margin: 0; }
    .su-menu-item {
        display: flex; align-items: center; gap: 0.5rem; width: 100%; min-height: 36px; padding: 0.55rem 0.7rem;
        border: 0; border-radius: 8px; background: transparent; color: #4A3B36;
        font-family: inherit; font-size: 0.8rem; font-weight: 600; text-align: left; white-space: nowrap; cursor: pointer;
    }
    .su-menu-item:hover, .su-menu-item:focus-visible { background: #FFF4EC; color: #8B1A1A; outline: none; }
    .su-menu-item.is-danger { color: #C0392B; }
    .su-menu-ico { display: inline-block; width: 1.2em; text-align: center; }

    /* ── Footer / pager ── */
    .su-pager {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.7rem;
        padding: 0.8rem 0.25rem 0; margin-top: 0.75rem;
    }
    .su-pager-info { font-size: 0.76rem; color: #8B7A72; font-weight: 600; }
    .su-pager-nav { display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; }
    .su-page-btn {
        min-width: 32px; height: 32px; padding: 0 0.5rem; display: inline-flex; align-items: center; justify-content: center;
        background: #fff; border: 1px solid #F0E2D5; border-radius: 9px; text-decoration: none; cursor: pointer;
        font-size: 0.76rem; font-weight: 700; color: #5B4740;
    }
    .su-page-btn:hover:not(.is-disabled) { border-color: #F4845F; color: #C0392B; }
    .su-page-btn.is-disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
    .su-page-btn.is-active { background: linear-gradient(135deg, #F4845F, #EF8585); color: #fff; border-color: transparent; }
    .su-page-gap { color: #C4B6AE; padding: 0 0.15rem; }

    .su-empty { text-align: center; padding: 2rem 1rem; color: #9A837B; font-size: 0.85rem; }
    .su-empty i { font-size: 2.5rem; display: block; margin-bottom: 0.5rem; color: #E5D6CF; }
    .su-empty a { color: #C0392B; font-weight: 600; }

    /* ── Phone: one card per account instead of a table ──
       The same <table> restyled (display:block), not a second copy of the
       markup, so the Edit and Set Password rows keep their one set of field
       ids. [hidden] has to be restated: an author `display:block` beats the
       browser's own `[hidden]{display:none}`. */
    @media (max-width: 640px) {
        .su-table-wrap { border: 0; overflow-x: visible !important; }
        #staffTable thead { display: none; }
        #staffTable, #staffTable tbody, #staffTable tr, #staffTable td { display: block; width: 100%; }
        #staffTable tr[hidden] { display: none; }
        #staffTable tbody tr.su-row {
            position: relative; margin-bottom: 0.65rem; padding: 0.25rem 0.1rem;
            background: #fff; border: 1px solid rgba(244, 132, 95, 0.28); border-radius: 14px;
        }
        #staffTable tbody tr.su-row td { border-top: 0; }
        #staffTable tbody tr.su-row:hover td { background: transparent; }
        #staffTable tbody td[data-label] {
            display: grid; grid-template-columns: 30% minmax(0, 70%); gap: 0.5rem; align-items: center;
            padding: 0.45rem 0.8rem; text-align: left; overflow-wrap: anywhere;
        }
        #staffTable tbody td[data-label]::before {
            content: attr(data-label); font-size: 0.66rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em; color: #999;
        }
        /* The name leads the card, with the ⋮ at its right. */
        #staffTable tbody td.su-name { display: block; padding: 0.7rem 3.4rem 0.35rem 0.8rem; font-size: 0.95rem; }
        #staffTable tbody td.su-name::before { content: none; }
        #staffTable tbody td.su-actions {
            position: absolute; top: 0.45rem; right: 0.55rem; width: auto; padding: 0; display: block;
        }
        #staffTable tbody td.su-actions::before { content: none; }
        /* The inline Edit / Set Password rows: full card width, one field per row. */
        #staffTable tbody tr.su-subrow { margin: -0.3rem 0 0.65rem; border: 1px solid #eee; border-radius: 12px; overflow: hidden; }
        #staffTable tbody tr.su-subrow td { padding: 0.6rem 0.7rem 1rem !important; }
        tr[id^="edit-row-"] form { grid-template-columns: minmax(0, 1fr) !important; }
        .su-pager { justify-content: center; }
    }
</style>

<p class="page-title">Staff Accounts</p>

{{-- Errors from the per-row Edit / Set Password forms (and anything that is not
     the create form) still need somewhere to appear: their fields live in a
     hidden row. The create form's own errors are shown under its fields below. --}}
@php $createFailed = old('_form') === 'create'; @endphp
@if($errors->any() && ! $createFailed)
<div class="su-banner" role="alert">
    @foreach($errors->all() as $error)
        <div>{{ $error }}</div>
    @endforeach
</div>
@endif

<div class="su-cols">

    {{-- LEFT: Create account --}}
    <section class="su-card" aria-labelledby="suCreateTitle">
        <div class="su-card-head">
            <h2 class="su-card-title" id="suCreateTitle"><i class="bi bi-person-plus"></i> Create Portal Account</h2>
        </div>
        <p class="su-card-sub">
            Only admins can create portal accounts as <strong>staff</strong> or <strong>supervisor</strong> —
            never another admin. Both roles are locked to the branch you assign below.
        </p>

        <form action="{{ route('admin.users.store') }}" method="POST" autocomplete="off" id="suCreateForm">
            <input type="text" name="prevent_autofill_username" id="prevent_autofill_username" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
            <input type="password" name="prevent_autofill_password" id="prevent_autofill_password" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
            @csrf
            {{-- Tells the page, after a failed save, that these errors belong to
                 THIS form (the Edit forms share the field names). --}}
            <input type="hidden" name="_form" value="create">

            <div class="su-field">
                <label class="form-label-custom" for="create-staff-name">Full Name</label>
                <input type="text" id="create-staff-name" name="name" required maxlength="255"
                    value="{{ $createFailed ? old('name') : '' }}" placeholder="Enter full name" readonly onfocus="this.removeAttribute('readonly');" autocomplete="off"
                    data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                    @if($createFailed && $errors->has('name')) aria-invalid="true" aria-describedby="su-err-name" @endif
                    class="form-control-custom">
                @if($createFailed && $errors->has('name'))<p class="su-err" id="su-err-name">{{ $errors->first('name') }}</p>@endif
            </div>

            <div class="su-field">
                <label class="form-label-custom" for="create-staff-email">Email</label>
                <input type="email" id="create-staff-email" name="email" required maxlength="255"
                    value="{{ $createFailed ? old('email') : '' }}" placeholder="name@example.com" readonly onfocus="this.removeAttribute('readonly');" autocomplete="off"
                    data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                    @if($createFailed && $errors->has('email')) aria-invalid="true" aria-describedby="su-err-email" @endif
                    class="form-control-custom">
                @if($createFailed && $errors->has('email'))<p class="su-err" id="su-err-email">{{ $errors->first('email') }}</p>@endif
            </div>

            {{-- Role. The options come from $assignableRoles, which is
                 AdminController::assignableRoles() — the SAME method
                 storeUser() and updateUser() pass to Rule::in(). So this list
                 cannot drift into offering something the server would refuse,
                 nor into offering 'admin', which neither constant behind that
                 method contains.

                 For a SUPERVISOR the list is 'staff' alone: "Change Staff
                 Role" is Y | N | N, so a manager may only create accounts at
                 the tier below their own. The select is not the control either
                 way — a hand-crafted POST with role=supervisor from a
                 supervisor fails validation with a 422. --}}
            <div class="su-field">
                <label class="form-label-custom" for="create-staff-role">Role</label>
                <select name="role" id="create-staff-role" required class="form-control-custom"
                    @if($createFailed && $errors->has('role')) aria-invalid="true" aria-describedby="su-err-role" @endif>
                    @foreach($assignableRoles as $roleOption)
                        <option value="{{ $roleOption }}"
                            {{ ($createFailed ? old('role', 'staff') : 'staff') === $roleOption ? 'selected' : '' }}>
                            {{ ucfirst($roleOption) }}
                        </option>
                    @endforeach
                </select>
                @if($createFailed && $errors->has('role'))<p class="su-err" id="su-err-role">{{ $errors->first('role') }}</p>@endif
            </div>

            {{-- Assigned Branch starts on the branch being viewed (the "Viewing:"
                 bar), and stays changeable. After a failed save it re-selects
                 what was submitted instead. --}}
            <div class="su-field">
                <label class="form-label-custom" for="create-staff-branch">Assigned Branch</label>
                <select name="branch_id" id="create-staff-branch" required class="form-control-custom" data-su-branch
                    @if($createFailed && $errors->has('branch_id')) aria-invalid="true" aria-describedby="su-err-branch" @endif>
                    <option value="">-- Select branch --</option>
                    @php $suBranchChoice = $createFailed ? old('branch_id') : $defaultBranchId; @endphp
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ (string) $suBranchChoice === (string) $b->id ? 'selected' : '' }}>
                            {{ $b->name }}{{ $b->is_active ? '' : ' (closed)' }}
                        </option>
                    @endforeach
                </select>
                @if($createFailed && $errors->has('branch_id'))<p class="su-err" id="su-err-branch">{{ $errors->first('branch_id') }}</p>@endif
            </div>

            <div class="su-field">
                <div class="su-pw-grid">
                    <div>
                        <label class="form-label-custom" for="create-staff-pw">Password</label>
                        <div class="pw-field">
                            <input type="password" id="create-staff-pw" name="password" required minlength="8" maxlength="20"
                                autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                                @if($createFailed && $errors->has('password')) aria-invalid="true" aria-describedby="su-err-password" @endif
                                class="form-control-custom">
                            <button type="button" class="js-pw-toggle" data-target="create-staff-pw"
                                aria-label="Show password" aria-pressed="false" title="Show password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="form-label-custom" for="create-staff-pw-confirm">Confirm Password</label>
                        <div class="pw-field">
                            <input type="password" id="create-staff-pw-confirm" name="password_confirmation" required minlength="8" maxlength="20"
                                autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                                class="form-control-custom">
                            <button type="button" class="js-pw-toggle" data-target="create-staff-pw-confirm"
                                aria-label="Show password" aria-pressed="false" title="Show password">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>
                @if($createFailed && $errors->has('password'))<p class="su-err" id="su-err-password">{{ $errors->first('password') }}</p>@endif
            </div>

            <p class="su-help">Password must be 8–20 characters. Use a combination that is difficult to guess.</p>

            <button type="submit" class="su-submit">
                <i class="bi bi-check2-circle"></i> Create Account
            </button>
        </form>
    </section>

    {{-- RIGHT: All Portal Accounts --}}
    <section class="su-card" aria-labelledby="suListTitle">
        <div class="su-card-head">
            <h2 class="su-card-title" id="suListTitle">All Portal Accounts <span class="su-count" id="suCount" data-testid="staff-count">{{ $scopeTotal }}</span></h2>

            {{-- A plain GET form: the URL carries q (and the Viewing branch), so
                 a search can be reloaded, bookmarked and paged through, and
                 with no JavaScript pressing Enter submits it as a normal page
                 load. With JavaScript the script below fetches the same URL in
                 the background and swaps only #suResults, so this box — and the
                 cursor in it — is never replaced. --}}
            <form action="{{ route('admin.users') }}" method="GET" class="su-search" role="search" data-testid="staff-search">
                {{-- The owner's "Viewing:" branch (the bar at the top of the
                     page). Not a filter, and the one value the bar changes.
                     Absent for a supervisor, who has no choice to carry. --}}
                @if($lockedBranchId === null)
                    <input type="hidden" name="branch" value="{{ $viewingBranchId ?? 'all' }}" data-su-viewing>
                @endif
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $search }}" maxlength="100"
                    placeholder="Search staff accounts..." aria-label="Search staff accounts" autocomplete="off">
            </form>
        </div>

        {{-- Shown only when a background update fails; the previous list stays. --}}
        <p class="su-error" id="suError" role="alert" hidden>
            Couldn't update the list. Showing the previous results.
            <button type="button" id="suRetry">Try again</button>
        </p>

        @include('admin.partials.staff-list-results', ['staff' => $staff, 'scopeTotal' => $scopeTotal, 'search' => $search, 'viewingBranchId' => $viewingBranchId])
    </section>

</div>

@endsection

@push('scripts')

{{-- The ⋮ menus. One open at a time, positioned against the viewport (fixed) so
     the table's own scrollbox never clips them. It closes on an outside click,
     Escape, a resize, or a real scroll of the page or the table — but not in
     the first moments after it opens: a scroll already in flight used to close
     a fixed popover the frame after it opened. Edit and Set Password are
     ordinary buttons handled by the shared .js-toggle-pw-row listener; this
     script only has to get the menu out of the way when one is chosen. --}}
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
        var btn = e.target.closest ? e.target.closest('[data-su-menu-btn]') : null;
        if (btn) {
            if (btn === openBtn) { closeMenu(false); } else { openMenu(btn); }
            return;
        }
        if (!openBtn) return;
        if (!(e.target.closest && e.target.closest('.su-menu'))) { closeMenu(false); return; }
        // Any choice closes the menu and hands focus back to its ⋮ button:
        // Edit / Set Password open a row and do not navigate, and a cancelled
        // Deactivate / Delete confirm leaves the page where it was. (Closing
        // does not stop a submit button's form from submitting — the click has
        // already been dispatched. Edit's own focus move to the row it opens
        // runs after this, in the shared .js-toggle-pw-row listener.)
        if (e.target.closest('.su-menu-item')) closeMenu(true);
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
    // The background refresh replaces the rows, and their menus with them;
    // close any open one first so no stale button is left "open".
    document.addEventListener('su:before-swap', function () { closeMenu(false); });
    window.addEventListener('scroll', function () {
        if (openBtn && Date.now() - openedAt > 300) closeMenu(false);
    }, true);
})();
</script>

{{-- Search, paging and the "Viewing:" bar without a page reload.

     A pause in typing, a pager click or a change of Viewing branch fetches the
     SAME address (same controller, same middleware, same permission checks)
     with the header X-Staff-Fragment: 1, which makes showUsers() answer with
     only the results region. That region (#suResults) is swapped in place; the
     create form and the search box are never touched, so nothing typed there is
     lost. Per-row actions need no re-wiring: the ⋮ menu is one delegated
     listener, and Edit / Set Password / Activate / Delete are ordinary buttons
     and <form>s inside the swapped HTML (each with a fresh CSRF token).

     Nothing here is required for the page to work: the search is a plain GET
     form and every pager link is a real href. --}}
<script>
(function () {
    var form = document.querySelector('[data-testid="staff-search"]');
    var region = document.getElementById('suResults');
    var errorBox = document.getElementById('suError');
    var createForm = document.getElementById('suCreateForm');
    var branchSelect = createForm ? createForm.elements.branch_id : null;
    var viewingEl = form ? form.querySelector('[data-su-viewing]') : null;

    // The create form's Assigned Branch follows the Viewing bar until the admin
    // picks one themselves; a branch they chose by hand is never overridden.
    var branchTouched = false;
    if (branchSelect) {
        branchSelect.addEventListener('change', function () { branchTouched = true; });
    }
    function followViewing(value) {
        if (!branchSelect || branchTouched) { return; }
        var wanted = (value && value !== 'all') ? String(value) : '';
        var has = Array.prototype.some.call(branchSelect.options, function (o) { return o.value === wanted; });
        branchSelect.value = has ? wanted : '';
    }

    if (!form || !region || !errorBox || !window.fetch || !window.URL || !window.AbortController) {
        // No background refresh available: the Viewing bar navigates instead.
        if (viewingEl) {
            window.pcBranchSelectHandler = function (value) {
                window.location.href = '{{ route('admin.users') }}?branch=' + encodeURIComponent(value || 'all');
                return true;
            };
        }
        return;
    }

    var countEl = document.getElementById('suCount');
    var searchEl = form.elements.q;
    var DEBOUNCE_MS = 300;
    var timer = null;
    var latest = 0;          // sequence number of the newest request
    var inflight = null;     // its AbortController
    var lastUrl = null;      // what "Try again" repeats

    // The address for the current search + Viewing branch. A changed search or
    // branch starts again at page 1, so `page` is dropped; an empty search is
    // left out so the URL stays clean.
    function listUrl() {
        var url = new URL(window.location.href);
        url.searchParams.delete('page');
        var q = searchEl.value.trim();
        if (q === '') { url.searchParams.delete('q'); } else { url.searchParams.set('q', q); }
        if (viewingEl) { url.searchParams.set('branch', viewingEl.value || 'all'); }
        return url;
    }

    function setBusy(on) {
        region.classList.toggle('is-loading', on);
        region.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    function load(url) {
        clearTimeout(timer);
        if (inflight) { inflight.abort(); }
        var mine = ++latest;
        var controller = new AbortController();
        inflight = controller;
        lastUrl = url;
        setBusy(true);

        fetch(url.href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Staff-Fragment': '1', 'Accept': 'text/html' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal
        })
            .then(function (response) {
                if (mine !== latest) { return null; }
                // Sent somewhere else (the login page after a session ended):
                // let the browser go there for real instead of guessing.
                if (response.redirected) { window.location.assign(url.href); return null; }
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            })
            .then(function (html) {
                if (html === null || mine !== latest) { return; }

                var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('suResults');
                if (!fresh) { throw new Error('no results region in response'); }

                document.dispatchEvent(new CustomEvent('su:before-swap'));
                region.innerHTML = fresh.innerHTML;
                region.dataset.total = fresh.dataset.total;
                region.dataset.scopeTotal = fresh.dataset.scopeTotal;
                if (countEl) { countEl.textContent = fresh.dataset.scopeTotal; }

                // Only now does the address change, so a failed update never
                // leaves a URL that does not match the list on screen.
                window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
                errorBox.hidden = true;
                setBusy(false);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') { return; }   // superseded; the newer request owns the state
                if (mine !== latest) { return; }
                errorBox.hidden = false;
                setBusy(false);
            });
    }

    function applyNow() { load(listUrl()); }

    // Typing: wait for a pause, and ignore anything an older request returns.
    searchEl.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(applyNow, DEBOUNCE_MS);
    });

    // Enter in the search box: apply now, no navigation.
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        applyNow();
    });

    // "Clear search" (the empty-state link).
    document.addEventListener('click', function (e) {
        var clear = e.target.closest ? e.target.closest('[data-su-clear]') : null;
        if (!clear || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        e.preventDefault();
        searchEl.value = '';
        applyNow();
    });

    // Pager links: same background swap, search + branch already in each href.
    region.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a.su-page-btn') : null;
        if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var href = link.getAttribute('href');
        if (!href || href === '#' || link.getAttribute('aria-disabled') === 'true') { return; }
        e.preventDefault();
        load(new URL(href, window.location.href));
    });

    document.getElementById('suRetry').addEventListener('click', function () {
        load(lastUrl || listUrl());
    });

    // The "Viewing:" bar at the top of the page (owner only): switch the list
    // here, in the background, keeping the search. The server remembers the
    // branch, so every other admin page opens on it too.
    if (viewingEl) {
        window.pcBranchSelectHandler = function (value, select) {
            viewingEl.value = value || 'all';
            var label = document.querySelector('.pc-branchbar .value');
            var option = select && select.options[select.selectedIndex];
            if (label && option) { label.textContent = option.text.trim(); }
            followViewing(viewingEl.value);
            applyNow();
            return true;
        };
    }
})();
</script>

@endpush

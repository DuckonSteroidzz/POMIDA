@extends('admin.layout')

@section('title', 'Staff Accounts - Peachy Admin')

@section('content')

<style>
    /* Two-up on wide screens, single column once there isn't room — matching
       the collapse pattern already used on menu-options / vouchers / summary.
       Without this the "All Staff" table's min-content width forced BOTH grid
       tracks wide and pushed the whole page sideways on mobile; the table's
       own overflow-x wrapper could not help while the 1fr track refused to
       shrink. */
    .staff-cols {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 1.25rem;
    }
    @media (max-width: 820px) {
        /* minmax(0, ...) not 1fr: a bare 1fr track still takes its min size
           from the content's min-content, which the staff table blows past. */
        .staff-cols { grid-template-columns: minmax(0, 1fr); }
    }

    /* Same scrolling-shadows technique as completed-orders.blade.php's
       .co-table-card and inventory.blade.php's .iv-table-card. The 6-column
       table (789px natural width) still doesn't fit its own wrapper once
       .staff-cols splits into two side-by-side cards above 820px — there was
       no cue at all that Role/Branch/Status/Action kept going past the
       right edge. */
    @media (min-width: 768px) {
        .su-table-wrap {
            background-image:
                linear-gradient(to right, #fff 30%, rgba(255,255,255,0)),
                linear-gradient(to left, #fff 30%, rgba(255,255,255,0)),
                linear-gradient(to right, rgba(0,0,0,.16), rgba(0,0,0,0) 6px),
                linear-gradient(to left, rgba(0,0,0,.16), rgba(0,0,0,0) 6px);
            background-position: left, right, left, right;
            background-repeat: no-repeat;
            background-size: 40px 100%, 40px 100%, 6px 100%, 6px 100%;
            background-attachment: local, local, scroll, scroll;
        }
    }

    /* ── Mobile: the All Portal Accounts table becomes cards ──
       Matching the pattern already used on completed-orders.blade.php /
       inventory.blade.php. Before this fix, only Name and Email fit in the
       viewport below 768px — Role, Branch, Status and every action button
       scrolled off with no visual cue at all, since #staffTable had nothing
       but a bare overflow-x:auto wrapper around a normal table. The same
       auto-layout table also forced the inline Edit form's 2-column grid to
       ~480px wide regardless of its own CSS, because an HTML table sizes
       every cell — including a hidden colspan row — from the widest content
       across ALL rows; only display:block here (not a narrower grid alone)
       removes that constraint. */
    @media (max-width: 767px) {
        #staffTable thead { display: none; }
        #staffTable, #staffTable tbody, #staffTable tr, #staffTable td {
            display: block; width: 100%;
        }
        #staffTable tbody tr.su-row {
            background: #fff; border: 1px solid #eee; border-radius: 12px;
            box-shadow: 0 8px 20px -18px rgba(0,0,0,.35);
            padding: 0.2rem 0.1rem; margin-bottom: 0.6rem;
        }
        /* [data-label] only — NOT the bare colspan="6" cells that hold the
           Edit / Set Password forms below. Those still need the block
           layout from the rule above, but not this label+value grid: an
           untargeted `td` selector here once matched them too, squeezing
           the whole inline form into the 66% value column (with an empty,
           label-less 34% column wasting the rest) instead of using the
           full card width. */
        #staffTable tbody td[data-label] {
            display: grid; grid-template-columns: 34% minmax(0,66%);
            gap: 0.5rem; align-items: center;
            border-bottom: 1px dashed #f0f0f0 !important;
            padding: 0.5rem 0.7rem !important; text-align: left !important;
            /* A long, unbroken email address (no spaces to wrap at) was the
               one thing still forcing the card a few px past the viewport —
               let it wrap onto a second line instead of stretching the row. */
            overflow-wrap: anywhere;
        }
        #staffTable tbody td[data-label]:last-child { border-bottom: none !important; }
        #staffTable tbody td[data-label]::before {
            content: attr(data-label); font-size: 0.66rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em; color: #999;
        }
        /* The colspan cells (Edit / Set Password forms) get normal padding
           and a bottom border of their own, matching the card's inner
           rhythm now that the label+value grid above skips them. */
        #staffTable tbody td:not([data-label]) {
            padding: 0.6rem 0.7rem 1rem !important;
        }
        /* The action cell holds 4 separate buttons, not one value — it reads
           better as its own full-width stack than squeezed into a 66% value
           column next to a single "ACTIONS" label. */
        #staffTable tbody td.su-actions {
            display: flex; flex-direction: column; gap: 0.45rem;
            text-align: left !important;
        }
        #staffTable tbody td.su-actions::before { margin-bottom: 0.15rem; }
        /* Full-width, taller buttons — the originals render at ~25px tall,
           well under the ~44px a thumb needs. !important because each
           button carries its own inline style="" (higher specificity than
           any class here), and this is a mobile-only override of exactly
           that — desktop keeps the original compact inline buttons. */
        #staffTable tbody td.su-actions button {
            width: 100% !important;
            margin-right: 0 !important;
            padding: 0.7rem 0.8rem !important;
            font-size: 0.78rem !important;
            min-height: 44px;
        }
        #staffTable tbody td.su-actions form { width: 100%; margin: 0 !important; }

        /* The inline Edit row's 2-column form (Name/Email/Contact/Role/
           Branch) — one field per row, like every other admin form at this
           width. */
        tr[id^="edit-row-"] form {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<p class="page-title">Staff Accounts</p>

@if($errors->any())
<div style="background:#f8d7da; color:#721c24; padding:0.6rem 0.85rem; border-radius:8px; font-size:0.82rem; margin-bottom:1rem;">
    @foreach($errors->all() as $error)
        <div>{{ $error }}</div>
    @endforeach
</div>
@endif

<div class="staff-cols">

    {{-- LEFT: Create staff form --}}
    <div class="content-card">
        <p style="font-size:0.9rem; font-weight:700; color:#333; margin-bottom:0.25rem;">
            <i class="bi bi-person-plus"></i> Create Portal Account
        </p>
        <p style="font-size:0.72rem; color:#888; margin-bottom:0.85rem;">
            Only admins can create portal accounts, and only as
            <strong>staff</strong> or <strong>supervisor</strong> — never another admin.
            Both roles are locked to the branch you assign below.
        </p>

        <form action="{{ route('admin.users.store') }}" method="POST" autocomplete="off">
            <input type="text" name="prevent_autofill_username" id="prevent_autofill_username" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
            <input type="password" name="prevent_autofill_password" id="prevent_autofill_password" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
            @csrf

            <label class="form-label-custom">Full Name</label>
            <input type="text" name="name" required maxlength="255"
                value="{{ old('name') }}" readonly onfocus="this.removeAttribute('readonly');" autocomplete="off"
                data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                class="form-control-custom">

            <label class="form-label-custom">Email</label>
            <input type="email" name="email" required maxlength="255"
                value="{{ old('email') }}" readonly onfocus="this.removeAttribute('readonly');" autocomplete="off"
                data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                class="form-control-custom">

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
            <label class="form-label-custom" for="create-staff-role">Role</label>
            <select name="role" id="create-staff-role" required class="form-control-custom">
                @foreach($assignableRoles as $roleOption)
                    <option value="{{ $roleOption }}"
                        {{ old('role', 'staff') === $roleOption ? 'selected' : '' }}>
                        {{ ucfirst($roleOption) }}
                    </option>
                @endforeach
            </select>

            <label class="form-label-custom">Assigned Branch</label>
            <select name="branch_id" required class="form-control-custom">
                <option value="">-- Select branch --</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>
                        {{ $b->name }}{{ $b->is_active ? '' : ' (closed)' }}
                    </option>
                @endforeach
            </select>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.6rem;">
                <div>
                    <label class="form-label-custom" for="create-staff-pw">Password</label>
                    <div class="pw-field">
                        <input type="password" id="create-staff-pw" name="password" required minlength="8" maxlength="20"
                            autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
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

            <p style="font-size:0.7rem; color:#888; margin:0.5rem 0 0.2rem; line-height:1.4;">
                {{ \App\Support\PasswordPolicy::describe() }}
            </p>

            <button type="submit" class="btn-primary-custom" style="width:100%; padding:0.6rem; margin-top:0.4rem;">
                <i class="bi bi-check2-circle"></i> Create Account
            </button>
        </form>
    </div>

    {{-- RIGHT: Staff list --}}
    <div class="content-card">
        <p style="font-size:0.9rem; font-weight:700; color:#333; margin-bottom:0.75rem;">
            All Portal Accounts ({{ $staff->count() }})
        </p>

        @if($staff->isEmpty())
            <div style="text-align:center; color:#aaa; padding:1.5rem; font-size:0.85rem;">
                <i class="bi bi-people" style="font-size:2rem; display:block; margin-bottom:0.5rem; color:#ddd;"></i>
                No staff or supervisor accounts yet. Use the form on the left to add one.
            </div>
        @else
            {{-- Horizontal scroll wrapper, matching the pattern already used on
                 account.blade.php / ads.blade.php / vouchers.blade.php — this
                 table has 5 columns including Email, which does not wrap, so
                 without this a narrow screen pushed the whole page sideways
                 instead of just this table. --}}
            <div class="su-table-wrap" style="overflow-x:auto;">
            <table id="staffTable" style="width:100%; font-size:0.8rem;">
                <thead>
                    <tr style="text-align:left; color:#888; font-size:0.7rem;">
                        <th style="padding:0.3rem 0.4rem;">Name</th>
                        <th style="padding:0.3rem 0.4rem;">Email</th>
                        <th style="padding:0.3rem 0.4rem;">Role</th>
                        <th style="padding:0.3rem 0.4rem;">Branch</th>
                        <th style="padding:0.3rem 0.4rem;">Status</th>
                        <th style="padding:0.3rem 0.4rem; text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($staff as $s)
                    <tr style="border-top:1px solid #f0f0f0;" class="su-row">
                        <td style="padding:0.4rem;" data-label="Name">{{ $s->name }}</td>
                        <td style="padding:0.4rem; color:#666;" data-label="Email">{{ $s->email }}</td>
                        <td style="padding:0.4rem;" data-label="Role">
                            <span style="background:{{ $s->role === 'supervisor' ? '#e7d9f5' : '#e3eaf2' }}; color:{{ $s->role === 'supervisor' ? '#4a2c68' : '#2c3e50' }}; padding:0.15rem 0.5rem; border-radius:10px; font-size:0.7rem; font-weight:600;">
                                {{ ucfirst($s->role) }}
                            </span>
                        </td>
                        <td style="padding:0.4rem;" data-label="Branch">{{ $s->branch->name ?? '—' }}</td>
                        <td style="padding:0.4rem;" data-label="Status">
                            @if($s->is_active)
                                <span style="background:#d4edda; color:#155724; padding:0.15rem 0.5rem; border-radius:10px; font-size:0.7rem; font-weight:600;">Active</span>
                            @else
                                <span style="background:#f8d7da; color:#721c24; padding:0.15rem 0.5rem; border-radius:10px; font-size:0.7rem; font-weight:600;">Disabled</span>
                            @endif
                        </td>
                        <td style="padding:0.4rem; text-align:right; white-space:nowrap;" data-label="Actions" class="su-actions">
                            {{-- "Edit Staff Information" — Y | Y | N. Same
                                 expandable-row pattern as Set Password below,
                                 rather than a modal or a separate page, so the
                                 two per-row forms behave identically. --}}
                            <button type="button"
                                class="js-toggle-pw-row"
                                data-target="edit-row-{{ $s->id }}"
                                aria-expanded="false"
                                aria-controls="edit-row-{{ $s->id }}"
                                style="background:#2c3e50; color:white; border:none; border-radius:6px; padding:0.25rem 0.55rem; font-size:0.7rem; cursor:pointer; margin-right:0.25rem;">
                                Edit
                            </button>

                            <button type="button"
                                class="js-toggle-pw-row"
                                data-target="pw-row-{{ $s->id }}"
                                aria-expanded="false"
                                aria-controls="pw-row-{{ $s->id }}"
                                style="background:#6c757d; color:white; border:none; border-radius:6px; padding:0.25rem 0.55rem; font-size:0.7rem; cursor:pointer; margin-right:0.25rem;">
                                Set Password
                            </button>

                            <form action="{{ route('admin.users.toggle', $s->id) }}" method="POST" style="margin:0; display:inline;"
                                onsubmit="return confirm('{{ $s->is_active ? 'Deactivate' : 'Reactivate' }} this staff account?')">
                                @csrf @method('PUT')
                                <button type="submit"
                                    style="background:{{ $s->is_active ? '#C0392B' : '#4CAF50' }}; color:white; border:none; border-radius:6px; padding:0.25rem 0.55rem; font-size:0.7rem; cursor:pointer; margin-right:0.25rem;">
                                    {{ $s->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </form>

                            {{-- Delete — irreversible, so it is the one action
                                 here that names the account in its confirm
                                 text and warns that deactivating is usually
                                 what was meant.

                                 Every row rendered on this page is already one
                                 the viewer may manage (manageableUsersQuery()
                                 and canManageAccount() are derived from the
                                 same two constants), so there is no per-row
                                 condition to add here — the LIMITED rule was
                                 applied when the list was built, and is
                                 applied again by destroyUser() against a
                                 crafted request. --}}
                            <form action="{{ route('admin.users.destroy', $s->id) }}" method="POST" style="margin:0; display:inline;"
                                onsubmit="return confirm('Permanently delete the account for {{ $s->name }}? This cannot be undone — deactivating keeps their history instead.')">
                                @csrf @method('DELETE')
                                <button type="submit"
                                    style="background:#8B1A1A; color:white; border:none; border-radius:6px; padding:0.25rem 0.55rem; font-size:0.7rem; cursor:pointer;">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>

                    {{-- Edit this account's details. Role and branch are
                         rendered from the same $assignableRoles / $branches
                         the create form uses, so a supervisor is offered
                         'staff' and their own branch only — and updateUser()
                         forces both server-side regardless of what is
                         posted. --}}
                    <tr id="edit-row-{{ $s->id }}" hidden>
                        <td colspan="6" style="padding:0.5rem 0.4rem 0.9rem; background:#fbfbfb;">
                            <form action="{{ route('admin.users.update', $s->id) }}" method="POST"
                                style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:0.5rem 0.75rem; align-items:end;">
                                @csrf @method('PUT')

                                <div>
                                    <label class="form-label-custom" for="edit-name-{{ $s->id }}">Name</label>
                                    <input type="text" id="edit-name-{{ $s->id }}" name="name" required maxlength="255"
                                        value="{{ $s->name }}" autocomplete="off" class="form-control-custom">
                                </div>

                                <div>
                                    <label class="form-label-custom" for="edit-email-{{ $s->id }}">Email</label>
                                    <input type="email" id="edit-email-{{ $s->id }}" name="email" required maxlength="255"
                                        value="{{ $s->email }}" autocomplete="off" class="form-control-custom">
                                </div>

                                <div>
                                    <label class="form-label-custom" for="edit-contact-{{ $s->id }}">Contact Number</label>
                                    <input type="text" id="edit-contact-{{ $s->id }}" name="contact_number" maxlength="30"
                                        value="{{ $s->contact_number }}" autocomplete="off" class="form-control-custom">
                                </div>

                                <div>
                                    <label class="form-label-custom" for="edit-role-{{ $s->id }}">Role</label>
                                    <select name="role" id="edit-role-{{ $s->id }}" required class="form-control-custom">
                                        @foreach($assignableRoles as $roleOption)
                                            <option value="{{ $roleOption }}" {{ $s->role === $roleOption ? 'selected' : '' }}>
                                                {{ ucfirst($roleOption) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="form-label-custom" for="edit-branch-{{ $s->id }}">Assigned Branch</label>
                                    <select name="branch_id" id="edit-branch-{{ $s->id }}" required class="form-control-custom">
                                        @foreach($branches as $b)
                                            <option value="{{ $b->id }}" {{ $s->branch_id == $b->id ? 'selected' : '' }}>
                                                {{ $b->name }}{{ $b->is_active ? '' : ' (closed)' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <button type="submit" class="btn-primary-custom" style="width:100%;">
                                        Save changes
                                    </button>
                                </div>
                            </form>
                        </td>
                    </tr>

                    {{-- Set a NEW password for this staff member.

                         There is deliberately no "show current password" here
                         and there never can be: stored passwords are bcrypt
                         hashes, so no existing password can be read back by
                         anyone, including the admin. The admin's control over a
                         staff account is exercised by SETTING a new password,
                         not by viewing the old one. --}}
                    <tr id="pw-row-{{ $s->id }}" hidden style="border-top:1px solid #f0f0f0; background:#fbfbfb;">
                        <td colspan="6" style="padding:0.6rem 0.4rem;">
                            <form action="{{ route('admin.users.password.update', $s->id) }}" method="POST"
                                onsubmit="return confirm('Set a new password for {{ $s->name }}? They will need to be told the new password.')">
                                <input type="text" name="prevent_autofill_username" id="prevent_autofill_username_{{ $s->id }}" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
                                <input type="password" name="prevent_autofill_password" id="prevent_autofill_password_{{ $s->id }}" value="" style="position:absolute; top:-9999px; left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off" />
                                @csrf @method('PUT')

                                <p style="font-size:0.72rem; color:#666; margin:0 0 0.5rem;">
                                    Set a new password for <strong>{{ $s->name }}</strong>.
                                    The current one cannot be shown — passwords are stored hashed and
                                    cannot be recovered by anyone. Tell {{ $s->name }} the new password directly.
                                </p>

                                <div style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:flex-end;">
                                    <div style="flex:1 1 200px;">
                                        <label for="pw-new-{{ $s->id }}" style="display:block; font-size:0.7rem; color:#555; margin-bottom:0.2rem;">
                                            New password
                                        </label>
                                        <div class="pw-field">
                                            <input type="password"
                                                id="pw-new-{{ $s->id }}"
                                                name="password"
                                                required
                                                minlength="8"
                                                maxlength="20"
                                                autocomplete="new-password"
                                                data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                                                class="form-control-custom">
                                            <button type="button" class="js-pw-toggle" data-target="pw-new-{{ $s->id }}"
                                                aria-label="Show password" aria-pressed="false" title="Show password">
                                                <i class="bi bi-eye" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div style="flex:1 1 200px;">
                                        <label for="pw-confirm-{{ $s->id }}" style="display:block; font-size:0.7rem; color:#555; margin-bottom:0.2rem;">
                                            Confirm new password
                                        </label>
                                        <div class="pw-field">
                                            <input type="password"
                                                id="pw-confirm-{{ $s->id }}"
                                                name="password_confirmation"
                                                required
                                                minlength="8"
                                                maxlength="20"
                                                autocomplete="new-password"
                                                data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                                                class="form-control-custom">
                                            <button type="button" class="js-pw-toggle" data-target="pw-confirm-{{ $s->id }}"
                                                aria-label="Show password" aria-pressed="false" title="Show password">
                                                <i class="bi bi-eye" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <button type="submit"
                                        style="background:#C0392B; color:white; border:none; border-radius:6px; padding:0.45rem 0.9rem; font-size:0.74rem; font-weight:600; cursor:pointer;">
                                        Update Password
                                    </button>
                                </div>

                                <p style="font-size:0.68rem; color:#888; margin:0.5rem 0 0;">
                                    {{ \App\Support\PasswordPolicy::describe() }}
                                </p>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

</div>

@endsection

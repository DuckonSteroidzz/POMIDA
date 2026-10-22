{{--
    The results region of Staff Accounts: the table (cards on a phone), the
    empty states and the pager. Drawn by admin.users for a normal page load and
    returned ALONE by AdminController::showUsers() for a background refresh
    (header X-Staff-Fragment: 1) — so a search, a page change or a change of the
    "Viewing:" branch swaps this one region and never touches the create form
    beside it (a half-typed account is not lost).

    $staff            LengthAwarePaginator of the portal accounts to show. Already
                      narrowed to what THIS viewer may manage, to the Viewing
                      branch and to the search — the view adds no scope of its own.
    $scopeTotal       accounts in view before any search (the count badge).
    $search           the search text ('' when none).
    $viewingBranchId  the branch being viewed, or null for All Branches.
    $branches, $assignableRoles   the edit form's dropdowns (unchanged).

    Every account listed is one the viewer may manage in full (the list and
    canManageAccount() are derived from the same two constants), so every row
    offers every action; the routes re-check the target regardless.
--}}
@php
    $viewingBranchName = $viewingBranchId !== null ? ($branches->firstWhere('id', $viewingBranchId)?->name) : null;
@endphp
<div id="suResults" data-testid="staff-results" data-total="{{ $staff->total() }}" data-scope-total="{{ $scopeTotal }}">
    @if($staff->count() > 0)
        {{-- Horizontal scroll wrapper, matching the pattern already used on
             account.blade.php / ads.blade.php / vouchers.blade.php: an email
             does not wrap, so without this a narrow screen pushed the whole
             page sideways instead of just this table. On a phone the table is
             restyled into one card per account (see the page's CSS). --}}
        <div class="su-table-wrap" style="overflow-x:auto;">
        <table id="staffTable" style="width:100%; font-size:0.8rem;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th class="su-th-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($staff as $s)
                <tr class="su-row" data-staff-row="{{ $s->id }}">
                    <td class="su-name" data-label="Name">{{ $s->name }}</td>
                    <td class="su-email" data-label="Email">{{ $s->email }}</td>
                    <td data-label="Role">
                        <span class="su-badge {{ $s->role === 'supervisor' ? 'is-supervisor' : 'is-staff' }}">{{ ucfirst($s->role) }}</span>
                    </td>
                    <td data-label="Branch">{{ $s->branch->name ?? '—' }}</td>
                    <td data-label="Status">
                        @if($s->is_active)
                            <span class="su-badge is-active">Active</span>
                        @else
                            <span class="su-badge is-disabled">Disabled</span>
                        @endif
                    </td>
                    <td class="su-actions" data-label="Actions">
                        <button type="button" class="su-kebab" data-su-menu-btn aria-haspopup="menu" aria-expanded="false"
                            aria-controls="suMenu{{ $s->id }}" aria-label="Actions for {{ $s->name }}" title="More actions">
                            <span aria-hidden="true">⋮</span>
                        </button>

                        {{-- The ⋮ menu. `hidden` until opened (display:none), so a
                             closed menu can never sit over another row's controls.
                             Edit and Set Password open the same inline rows they
                             always have (below) — only the button that opens them
                             moved into this menu. --}}
                        <div class="su-menu" id="suMenu{{ $s->id }}" role="menu" hidden>
                            {{-- "Edit Staff Information" — Y | Y | N. --}}
                            <button type="button" role="menuitem" class="su-menu-item js-toggle-pw-row"
                                data-target="edit-row-{{ $s->id }}"
                                aria-expanded="false"
                                aria-controls="edit-row-{{ $s->id }}">
                                <span class="su-menu-ico" aria-hidden="true">✏️</span> Edit
                            </button>

                            <button type="button" role="menuitem" class="su-menu-item js-toggle-pw-row"
                                data-target="pw-row-{{ $s->id }}"
                                aria-expanded="false"
                                aria-controls="pw-row-{{ $s->id }}">
                                <span class="su-menu-ico" aria-hidden="true">🔑</span> Set Password
                            </button>

                            {{-- The confirmation text rides in data-confirm, not
                                 inside an inline confirm('...') string: an
                                 account name is free text, and a quote in it
                                 would break out of the string and run as script. --}}
                            <form action="{{ route('admin.users.toggle', $s->id) }}" method="POST"
                                data-confirm="{{ $s->is_active ? 'Deactivate' : 'Reactivate' }} this staff account?"
                                onsubmit="return confirm(this.dataset.confirm)">
                                @csrf @method('PUT')
                                <button type="submit" role="menuitem" class="su-menu-item">
                                    @if($s->is_active)
                                        <span class="su-menu-ico" aria-hidden="true">⏸</span> Deactivate
                                    @else
                                        <span class="su-menu-ico" aria-hidden="true">✓</span> Reactivate
                                    @endif
                                </button>
                            </form>

                            {{-- Delete — irreversible, so it is the one action
                                 here that names the account in its confirm
                                 text and warns that deactivating is usually
                                 what was meant. Routed through destroyUser(),
                                 which re-checks the target and refuses an
                                 account that still has order history. --}}
                            <form action="{{ route('admin.users.destroy', $s->id) }}" method="POST"
                                data-confirm="Permanently delete the account for {{ $s->name }}? This cannot be undone — deactivating keeps their history instead."
                                onsubmit="return confirm(this.dataset.confirm)">
                                @csrf @method('DELETE')
                                <button type="submit" role="menuitem" class="su-menu-item is-danger">
                                    <span class="su-menu-ico" aria-hidden="true">🗑️</span> Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>

                {{-- Edit this account's details. Role and branch are
                     rendered from the same $assignableRoles / $branches
                     the create form uses, so a supervisor is offered
                     'staff' and their own branch only — and updateUser()
                     forces both server-side regardless of what is
                     posted. --}}
                <tr id="edit-row-{{ $s->id }}" class="su-subrow" hidden>
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
                <tr id="pw-row-{{ $s->id }}" class="su-subrow" hidden style="border-top:1px solid #f0f0f0; background:#fbfbfb;">
                    <td colspan="6" style="padding:0.6rem 0.4rem;">
                        <form action="{{ route('admin.users.password.update', $s->id) }}" method="POST"
                            data-confirm="Set a new password for {{ $s->name }}? They will need to be told the new password."
                            onsubmit="return confirm(this.dataset.confirm)">
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
    @elseif($search !== '')
        <div class="su-empty">
            <i class="bi bi-search"></i>
            No accounts match “{{ $search }}”@if($viewingBranchName) in {{ $viewingBranchName }}@endif.
            <a href="{{ route('admin.users') }}" class="su-clear" data-su-clear>Clear search</a>
        </div>
    @elseif($viewingBranchName)
        <div class="su-empty">
            <i class="bi bi-people"></i>
            No staff or supervisor accounts are assigned to {{ $viewingBranchName }} yet.
        </div>
    @else
        <div class="su-empty">
            <i class="bi bi-people"></i>
            No staff or supervisor accounts yet. Use the form on the left to add one.
        </div>
    @endif

    {{-- Footer: where this page sits in the matching accounts, and a numbered
         pager. The links are plain hrefs that carry the search and the Viewing
         branch (withQueryString); the page's script turns a click into a
         background swap, and without it they navigate normally. --}}
    @if($staff->total() > 0)
    <div class="su-pager">
        <span class="su-pager-info">
            Showing {{ $staff->firstItem() }}–{{ $staff->lastItem() }} of {{ $staff->total() }} matching {{ $staff->total() === 1 ? 'account' : 'accounts' }}
        </span>
        <nav class="su-pager-nav" aria-label="Staff accounts pages">
            <a href="{{ $staff->onFirstPage() ? '#' : $staff->previousPageUrl() }}"
                class="su-page-btn {{ $staff->onFirstPage() ? 'is-disabled' : '' }}" aria-label="Previous page"
                @if($staff->onFirstPage()) aria-disabled="true" onclick="return false;" @endif>
                <i class="bi bi-chevron-left"></i>
            </a>
            @php
                $suCurrent = $staff->currentPage();
                $suLast = $staff->lastPage();
                $suPageNumbers = collect();
                for ($i = 1; $i <= $suLast; $i++) {
                    if ($i === 1 || $i === $suLast || abs($i - $suCurrent) <= 1) {
                        $suPageNumbers->push($i);
                    } elseif ($suPageNumbers->last() !== '…') {
                        $suPageNumbers->push('…');
                    }
                }
            @endphp
            @foreach($suPageNumbers as $suPageNumber)
                @if($suPageNumber === '…')
                    <span class="su-page-gap">…</span>
                @else
                    <a href="{{ $staff->url($suPageNumber) }}"
                        class="su-page-btn {{ $suPageNumber === $suCurrent ? 'is-active' : '' }}"
                        @if($suPageNumber === $suCurrent) aria-current="page" @endif>{{ $suPageNumber }}</a>
                @endif
            @endforeach
            <a href="{{ $staff->hasMorePages() ? $staff->nextPageUrl() : '#' }}"
                class="su-page-btn {{ $staff->hasMorePages() ? '' : 'is-disabled' }}" aria-label="Next page"
                @if(! $staff->hasMorePages()) aria-disabled="true" onclick="return false;" @endif>
                <i class="bi bi-chevron-right"></i>
            </a>
        </nav>
    </div>
    @endif
</div>

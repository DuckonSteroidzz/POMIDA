{{--
    One voucher's ⋮ menu (Vouchers tab). Drawn twice per voucher by
    voucher-list-results — once in the table row, once in the phone card —
    so the two can never offer different actions.

    In the order of the tester's reference: Activate / Deactivate, Issue,
    Edit, Delete. Each item is shown only to a viewer the server would let do
    it — the menu agreeing with the routes, never a substitute for them:

      Activate / Deactivate, Edit — $canManage (owner: any voucher; supervisor:
          their own branch's only; staff: never). Unchanged routes:
          vouchers.toggle and the Edit modal's vouchers.update.
      Issue  — every viewer, while the voucher is issuable.
      Delete — the owner alone ("Delete Vouchers" is Y | N | N).

    A supervisor on a company-wide voucher sees "Read-only" in place of the
    changing actions. Staff see Issue only.

    The Issue and Delete confirmations read their text from data-confirm, not
    from inside the inline script: a voucher code is free text, and a quote in
    it used to break out of the confirm('...') string and run as script.

    $voucher, $menuId, $canManage, $canAuthorVouchers, $isAdmin, $issueBlocked
--}}
<div class="vch-menu" id="{{ $menuId }}" role="menu" hidden>
    @if($canManage)
    <form action="{{ route('admin.vouchers.toggle', $voucher->id) }}" method="POST">
        @csrf @method('PUT')
        <button type="submit" role="menuitem" class="vch-menu-item">
            @if($voucher->is_active)
            <span class="vch-menu-ico" aria-hidden="true">⏸</span> Deactivate Voucher
            @else
            <span class="vch-menu-ico" aria-hidden="true">✓</span> Activate Voucher
            @endif
        </button>
    </form>
    @endif

    {{-- Issue a bearer code for a walk-in customer who has no account and
         never played the wheel. Disabled, with the reason as a tooltip,
         whenever the voucher is not currently issuable — the server refuses it
         either way (Voucher::issuanceErrorFor), this just saves the admin a
         pointless round trip. --}}
    @if($issueBlocked === null)
    <form action="{{ route('admin.vouchers.issue-code', $voucher->id) }}" method="POST"
        data-confirm="Issue a new code for {{ $voucher->code }}? It counts against this voucher's usage limit."
        onsubmit="return confirm(this.dataset.confirm)">
        @csrf
        <button type="submit" role="menuitem" class="vch-menu-item" title="Issue a code for a walk-in customer">
            <span class="vch-menu-ico" aria-hidden="true">🎁</span> Issue Voucher
        </button>
    </form>
    @else
    <button type="button" role="menuitem" class="vch-menu-item is-disabled" disabled title="{{ $issueBlocked }}">
        <span class="vch-menu-ico" aria-hidden="true">🎁</span> Issue Voucher
    </button>
    @endif

    @if($canAuthorVouchers)
        @if($canManage)
        <button type="button" role="menuitem"
            class="vch-menu-item"
            data-id="{{ $voucher->id }}"
            data-code="{{ $voucher->code }}"
            data-description="{{ $voucher->description }}"
            data-discount-type="{{ $voucher->discount_type }}"
            data-discount-value="{{ $voucher->discount_value }}"
            data-max-uses="{{ $voucher->max_uses }}"
            data-minimum-order="{{ $voucher->minimum_order }}"
            data-valid-from="{{ $voucher->valid_from ? \Carbon\Carbon::parse($voucher->valid_from)->format('Y-m-d') : '' }}"
            data-expires-at="{{ $voucher->expires_at ? $voucher->expires_at->format('Y-m-d') : '' }}"
            data-points-required="{{ $voucher->points_required }}"
            data-is-active="{{ $voucher->is_active ? '1' : '0' }}"
            data-on-wheel="{{ $voucher->on_wheel ? '1' : '0' }}"
            data-branch="{{ $voucher->branch_id ?? '' }}"
            onclick="openEditVoucherModal(this)">
            <span class="vch-menu-ico" aria-hidden="true">✏️</span> Edit Voucher
        </button>
            @if($isAdmin)
            <form action="{{ route('admin.vouchers.delete', $voucher->id) }}" method="POST"
                data-confirm="Delete voucher {{ $voucher->code }}?" onsubmit="return confirm(this.dataset.confirm)">
                @csrf @method('DELETE')
                <button type="submit" role="menuitem" class="vch-menu-item is-danger">
                    <span class="vch-menu-ico" aria-hidden="true">🗑️</span> Delete Voucher
                </button>
            </form>
            @endif
        @else
        <span class="vch-menu-lock" title="Company-wide vouchers can only be changed by the owner."><span aria-hidden="true">🔒</span> Read-only</span>
        @endif
    @endif
</div>

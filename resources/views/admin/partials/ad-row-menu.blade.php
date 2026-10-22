{{--
    One ad's ⋮ menu (Ads Management tab). Drawn twice per ad by
    ad-list-results — once in the table row, once in the phone card — so the
    two can never offer different actions.

    Edit and Delete, as in the tester's reference, only for an ad the viewer
    may manage ($canManage mirrors AdminController::promotionScopeRefusal():
    the owner, any ad; a supervisor, their own branch's only). A supervisor on
    a company-wide ad gets "Read-only" instead. Both actions are unchanged:
    Edit opens the Edit modal (admin.ads.update), Delete is the same DELETE
    form with the same confirmation.

    The confirmation text rides in data-confirm rather than inside the inline
    script: an ad title is free text, and a quote in it used to break out of
    the confirm('...') string.

    $ad, $menuId, $canManage
--}}
<div class="ads-menu" id="{{ $menuId }}" role="menu" hidden>
    @if($canManage)
    <button type="button" role="menuitem"
        class="ads-menu-item"
        data-id="{{ $ad->id }}"
        data-title="{{ $ad->title }}"
        data-description="{{ $ad->description }}"
        data-image="{{ $ad->image ? \App\Support\Img::url($ad->image) : '' }}"
        data-link="{{ $ad->link }}"
        data-placement="{{ $ad->placement }}"
        data-starts-at="{{ $ad->starts_at ? $ad->starts_at->format('Y-m-d') : '' }}"
        data-ends-at="{{ $ad->ends_at ? $ad->ends_at->format('Y-m-d') : '' }}"
        data-branch="{{ $ad->branch_id ?? '' }}"
        onclick="openEditAdModal(this)">
        <span class="ads-menu-ico" aria-hidden="true">✏️</span> Edit
    </button>
    <form action="{{ route('admin.ads.delete', $ad->id) }}" method="POST"
        data-confirm="Delete ad {{ $ad->title }}?" onsubmit="return confirm(this.dataset.confirm)">
        @csrf @method('DELETE')
        <button type="submit" role="menuitem" class="ads-menu-item is-danger">
            <span class="ads-menu-ico" aria-hidden="true">🗑️</span> Delete
        </button>
    </form>
    @else
    <span class="ads-menu-lock" title="Company-wide ads can only be changed by the owner."><span aria-hidden="true">🔒</span> Read-only</span>
    @endif
</div>

@extends('admin.layout')

@section('title', 'Menu Items - Peachy Admin')

@section('content')

@php
    $adminUser = Auth::guard('admin')->user();

    /*
     * The permission matrix, as this page needs it.
     *
     * Add / Edit / Enable-Disable Menu Items are Y | Y | N, so they follow
     * isManager() — the same User::MANAGER_ROLES the `role:admin,supervisor`
     * route group is spelled from. Staff keep "View Menu Items" (Y | Y | Y),
     * which is this table without any of the controls.
     *
     * Delete is the LIMITED row and is decided PER ITEM below, not here: a
     * supervisor may delete only an item belonging to their own branch, never
     * a shared (branch_id IS NULL) one. $lockedBranchId is null for the owner,
     * which is what makes every branchDeletable() test below pass for them.
     */
    $canManageMenu  = $adminUser && $adminUser->isManager();
    $lockedBranchId = \App\Services\AdminOrderAccess::lockedBranchId();

    // Restoring an archived size is owner-only (role:admin on
    // admin.menu-items.sizes.restore, like every catalogue Restore). Hide,
    // never disable: a supervisor is told who can, not shown a dead button.
    $canRestoreSize = $adminUser && $adminUser->isAdmin();

    $canDeleteItem = function ($item) use ($canManageMenu, $lockedBranchId) {
        if (! $canManageMenu) {
            return false;
        }

        // Owner — not branch bound, so every item including shared ones.
        if ($lockedBranchId === null) {
            return true;
        }

        // Manager — own branch only, and never a shared item. Mirrors exactly
        // what AdminController::deleteMenuItem() enforces server-side; this is
        // the button agreeing with that check, never a substitute for it.
        return $item->branch_id !== null && (int) $item->branch_id === $lockedBranchId;
    };
@endphp

<p class="page-title">Menu Items</p>

{{-- Validation errors (e.g. a rejected image upload) — without this a failed
     edit redirects back here and looks like nothing happened. --}}
@if($errors->any())
<div style="background:#f8d7da; color:#721c24; padding:0.6rem 0.85rem; border-radius:8px; font-size:0.82rem; margin-bottom:1rem;">
    @foreach($errors->all() as $error)
        <div>{{ $error }}</div>
    @endforeach
</div>
@endif

{{-- Search + Filter --}}
<div class="content-card">
    <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <div class="search-wrapper" style="flex: 1; min-width: 200px;">
            <i class="bi bi-search"></i>
            <input type="text" class="search-input" style="width: 100%;" aria-label="Search menu items" id="searchInput" onkeyup="searchTable()" autocomplete="off">
        </div>
        <select class="form-control-custom" style="width: 160px; margin-bottom: 0;" id="categoryFilter" onchange="filterTable()">
            <option value="">Main Category:</option>
            @if(isset($categories))
            @foreach($categories as $category)
            <option value="{{ $category->name }}">{{ $category->name }}</option>
            @endforeach
            @endif
        </select>
        <select class="form-control-custom" style="width: 160px; margin-bottom: 0;" id="subCategoryFilter" onchange="filterTable()">
            <option value="">Sub Category:</option>
            @if(isset($subcategories))
            @foreach($subcategories as $sub)
            <option value="{{ $sub->name }}">{{ $sub->name }}</option>
            @endforeach
            @endif
        </select>
        @if($canManageMenu)
        @if(isset($selectedBranch) && $selectedBranch === 'all')
        <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:0.5rem 0.85rem;font-size:0.78rem;color:#856404;">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>All Branches is for viewing only.</strong> Select a specific branch before adding menu items.
        </div>
        @else
        <button type="button" class="btn-primary-custom" onclick="openAddModal()" style="padding: 0.55rem 1.25rem; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.4rem;">
            <i class="bi bi-plus-circle"></i> Add New Item
        </button>
        @endif
        @endif

        {{-- Archived is a PERMANENT part of this screen, always visible, even at
             zero — it is not a conditional badge that only shows up after
             something has been archived. That "how do I even get to the
             archive" was the exact bug reported: the only way to discover it
             was to have already archived something, with no way back to
             restore anything otherwise. Visible to admin AND staff, matching
             who can see this main list — seeing what is archived is read-only
             information. Restoring is the destructive action and stays
             admin-only, both on the route (admin.archived.restore) and inside
             admin.archived's own view. --}}
        <a href="{{ route('admin.archived') }}"
            style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1rem;border-radius:8px;border:1px solid #F6B49B;background:#fffaf6;color:#C0392B;font-size:0.82rem;font-weight:700;text-decoration:none;white-space:nowrap;">
            <i class="bi bi-archive"></i> Archived ({{ $archivedCount ?? 0 }})
        </a>
    </div>
</div>

{{-- Table --}}
<style>
    @media (max-width: 640px) {
        #menuTable, #menuTable tbody, #menuTable tr, #menuTable td { display: block; width: 100%; }
        #menuTable thead { display: none; }
        #menuTable tbody tr {
            background: #fffaf6; border: 1px solid #eee; border-radius: 12px;
            padding: 0.6rem 0.8rem; margin-bottom: 0.6rem;
        }
        #menuTable tbody td {
            border: 0 !important; padding: 0.3rem 0; text-align: right !important;
            display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
        }
        #menuTable tbody td::before {
            content: attr(data-label); font-size: 0.7rem; text-transform: uppercase;
            letter-spacing: 0.05em; font-weight: 700; color: #4B5563; text-align: left;
        }
        #menuTable tbody tr td:first-child { justify-content: flex-end; }
    }

    @media (max-width: 600px) {
        .recipe-ing-add-row { flex-wrap: wrap; }
        .recipe-ing-add-row > div { flex: 1 1 100% !important; min-width: 0 !important; }
    }

    /* ── Branch group headers, one per section of the table ──
       Reuses the page's own "which branch" visual language — the icon,
       label and bold serif value the top-of-page .pc-branchbar (layout.
       blade.php) already uses for "Viewing: <branch>" — as an in-table
       section divider, rather than inventing a new banner style. Not
       .pc-branchbar itself: that bar also carries the branch-switcher
       select, which has no place inside a table row. */
    .menu-branch-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: var(--pc-blush);
        border: 1px solid rgba(192, 57, 43, 0.16);
        border-radius: 10px;
        padding: 0.55rem 0.9rem;
        color: var(--pc-maroon);
    }
    .menu-branch-group .value {
        font-family: 'Fraunces', Georgia, serif;
        font-weight: 700;
        color: var(--pc-maroon);
        font-size: 0.95rem;
    }
    .menu-branch-group .count {
        margin-left: auto;
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--pc-mute);
        background: #fff;
        border-radius: 999px;
        padding: 0.15rem 0.55rem;
        white-space: nowrap;
    }
    #menuTable tbody tr.menu-branch-group-row { background: transparent; }
    #menuTable tbody tr.menu-branch-group-row:hover { background: transparent; }
    #menuTable tbody tr.menu-branch-group-row td {
        padding: 0.9rem 0.4rem 0.4rem;
        border: 0 !important;
    }
    #menuTable tbody tr.menu-branch-group-row:first-child td { padding-top: 0; }

    @media (max-width: 640px) {
        #menuTable tbody tr.menu-branch-group-row {
            border: none; padding: 0; margin-bottom: 0.4rem; background: transparent;
        }
        #menuTable tbody tr.menu-branch-group-row td {
            display: block !important; text-align: left !important; padding: 0.6rem 0 0.2rem;
        }
        #menuTable tbody tr.menu-branch-group-row td::before { content: none; }
    }

    /* ── Menu Item Sizes (Phase 1) ──
       Same frame as the Recipe Ingredients box, and the page's existing pill
       colours: green = fine, amber = needs attention (the "No recipe" badge),
       red = off, grey = archived. */
    .size-section { border: 2px solid #F4845F; border-radius: 10px; padding: 0.65rem 0.75rem; margin-bottom: 0.85rem; }
    .size-section-title { font-size: 0.85rem; font-weight: 700; color: #C0392B; margin: 0 0 0.5rem; }
    .size-hint { font-size: 0.74rem; color: #374151; font-weight: 500; margin: 0 0 0.55rem; }
    .size-enable-row, .size-edit-row { display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; }
    .size-enable-row > div, .size-price-field { flex: 1 1 140px; min-width: 0; }
    .size-card { border: 1px solid #f0d9cf; border-radius: 8px; padding: 0.55rem 0.65rem; margin-bottom: 0.55rem; background: #fffaf6; min-width: 0; }
    .size-card.is-archived { background: #f4f4f4; border-color: #e5e7eb; }
    .size-card-head { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 0.45rem; }
    .size-name { font-family: 'Fraunces', Georgia, serif; color: var(--pc-maroon); font-size: 0.95rem; }
    .size-chip { padding: 0.12rem 0.5rem; border-radius: 10px; font-size: 0.68rem; font-weight: 600; white-space: nowrap; }
    .size-chip-on { background: #d4edda; color: #155724; }
    .size-chip-off { background: #f8d7da; color: #721c24; }
    .size-chip-warn { background: #fff3cd; color: #856404; }
    .size-chip-muted { background: #e5e7eb; color: #374151; }
    .size-active-toggle { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.8rem; font-weight: 600; color: #374151; padding: 0.5rem 0.2rem; cursor: pointer; margin: 0; }
    .size-active-toggle input[type="checkbox"] { width: 16px; height: 16px; accent-color: #F4845F; cursor: pointer; }
    .size-btn { padding: 0.5rem 0.9rem; white-space: nowrap; }
    .size-archive-btn { background: #fff; color: #C0392B; border: 1px solid #F6B49B; border-radius: 8px; padding: 0.5rem 0.8rem; font-size: 0.8rem; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .size-recipe-block { border-top: 1px dashed #f0d9cf; margin-top: 0.55rem; padding-top: 0.5rem; }
    .size-recipe-title { font-size: 0.76rem; font-weight: 700; color: #1F2937; margin: 0 0 0.35rem; }
    .size-recipe-title span { font-weight: 500; color: #4B5563; }
    .menu-price-cell { display: inline-flex; flex-direction: column; align-items: inherit; gap: 0.1rem; }
    .menu-price-from { font-size: 0.7rem; font-weight: 600; color: #4B5563; }
    .menu-size-summary { display: flex; flex-direction: column; font-size: 0.7rem; font-weight: 600; color: #4B5563; white-space: nowrap; }
    .menu-size-summary .is-off { text-decoration: line-through; color: #6B7280; }

    @media (max-width: 768px) {
        .size-enable-row > div, .size-price-field { flex: 1 1 100%; }
        .size-edit-row .size-btn, .size-edit-row .size-archive-btn, .size-enable-row .size-btn { flex: 1 1 auto; }
    }
    @media (max-width: 640px) {
        .menu-price-cell { align-items: flex-end; }
    }
</style>
<div class="content-card" style="overflow-x: auto;">
    <table class="table-custom" id="menuTable">
        <thead>
            <tr>
                <th>Image</th>
                <th>Item Name</th>
                <th>Description</th>
                <th>Category</th>
                <th>Subcategory</th>
                <th>Price</th>
                <th>Cost</th>
                <th>Gross Profit</th>
                <th>Branch</th>
                <th>Available</th>
                @if($canManageMenu)
                <th>Edit</th>
                <th>Delete</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @if(isset($menuItems) && count($menuItems) > 0)
            @php
                /*
                 * Branch-grouped table.
                 *
                 * The controller (showMenuItems()) now orders $menuItems with
                 * non-null branches first (by id) and any shared/"All
                 * Branches" (branch_id IS NULL) items last, so a single pass
                 * here can print a section header the moment the branch
                 * changes rather than needing a second, pre-grouped
                 * structure. $menuTableColspan/$branchGroupCounts are the
                 * only things worked out ahead of the loop, both from the
                 * already-fetched collection — no extra query.
                 *
                 * A NULL branch_id gets its OWN section labelled "All
                 * Branches", the same wording the per-row Branch badge
                 * already uses two columns over, rather than being dropped
                 * or crashing on a missing ->branch relation.
                 */
                $menuTableColspan = $canManageMenu ? 12 : 10;
                $branchGroupCounts = $menuItems->groupBy(fn ($mi) => $mi->branch_id ?? 'unassigned')->map->count();
                $currentBranchGroup = null;
            @endphp
            @foreach($menuItems as $item)
                @php $branchGroupKey = $item->branch_id ?? 'unassigned'; @endphp
                @if($branchGroupKey !== $currentBranchGroup)
                    @php
                        $currentBranchGroup = $branchGroupKey;
                        $branchGroupLabel = $item->branch_id === null
                            ? 'All Branches'
                            : ($item->branch->name ?? 'Branch #'.$item->branch_id);
                        $branchGroupCount = $branchGroupCounts[$branchGroupKey];
                    @endphp
                    <tr class="menu-branch-group-row" data-branch-group="{{ $branchGroupKey }}">
                        <td colspan="{{ $menuTableColspan }}">
                            <div class="menu-branch-group">
                                <i class="bi bi-building"></i>
                                <span class="value">{{ $branchGroupLabel }}</span>
                                <span class="count">{{ $branchGroupCount }} item{{ $branchGroupCount === 1 ? '' : 's' }}</span>
                            </div>
                        </td>
                    </tr>
                @endif
            <tr data-branch-group="{{ $branchGroupKey }}">
                <td data-label="Image">
                    @if($item->image)
                    <img src="{{ \App\Support\Img::url($item->image) }}" style="width:45px; height:45px; border-radius:6px; object-fit:cover;">
                    @else
                    <div style="width:45px; height:45px; border-radius:6px; background:#f0f0f0; display:flex; align-items:center; justify-content:center;">
                        <i class="bi bi-image" style="color:#ccc;"></i>
                    </div>
                    @endif
                </td>
                <td data-label="Item Name" style="font-weight: 500;">{{ $item->name }}</td>
                <td data-label="Description" style="max-width: 200px; color: #374151; font-size: 0.78rem; font-weight: 500;">{{ $item->description ?? '-' }}</td>
                <td data-label="Category">{{ $item->category->name ?? '-' }}</td>
                <td data-label="Subcategory">{{ $item->subcategory->name ?? '-' }}</td>
                {{-- A sized item's price column is its "starting from" figure
                     (menu_items.price = lowest ACTIVE size), with each size's
                     own price under it; an inactive/archived size is struck
                     through. One wrapper element so the phone card layout's
                     label/value flex row gets exactly one value. --}}
                <td data-label="Price" style="font-weight: 600;">
                    @if($item->allSizes->isNotEmpty())
                    <span class="menu-price-cell">
                        <span><span class="menu-price-from">from</span> ₱{{ number_format($item->price, 2) }}</span>
                        <span class="menu-size-summary">
                            @foreach($item->allSizes as $listSize)
                            <span class="{{ $listSize->isLive() ? '' : 'is-off' }}">{{ $listSize->name }} ₱{{ number_format($listSize->price, 2) }}</span>
                            @endforeach
                        </span>
                    </span>
                    @else
                    ₱{{ number_format($item->price, 2) }}
                    @endif
                </td>
                {{-- Cost + Gross Profit come from App\Services\MenuItemCosting, which
                     sums quantity_used x unit_cost over the item's recipe. When the item
                     has no recipe at all the number is the typed-in guess, and it is
                     badged as such so the owner can tell a real figure from an estimate.
                     The badge reuses the same pill shape as the Branch column beside it
                     and the amber warning colours already used by the "All Branches is
                     for viewing only" notice at the top of this page. --}}
                @php $c = $costing[$item->id] ?? null; @endphp
                <td data-label="Cost" style="font-weight: 600;">
                    @if($c)
                    ₱{{ number_format($c['cost'], 2) }}
                    @if($c['is_fallback'])
                    <span style="background:#fff3cd;color:#856404;padding:0.15rem 0.5rem;border-radius:10px;font-size:0.7rem;font-weight:600;margin-left:0.35rem;white-space:nowrap;"
                          title="No recipe set — this is the manually typed cost, not a computed one.">
                        No recipe
                    </span>
                    @endif
                    @else
                    -
                    @endif
                </td>
                <td data-label="Gross Profit" style="font-weight: 600;">
                    @if($c)
                    <span style="color:{{ $c['profit'] < 0 ? '#C0392B' : '#155724' }};">
                        {{ $c['profit'] < 0 ? '-₱' . number_format(abs($c['profit']), 2) : '₱' . number_format($c['profit'], 2) }}
                    </span>
                    <span style="color:#4B5563;font-size:0.72rem;font-weight:600;">
                        @if($c['margin_percent'] === null)
                            (no price)
                        @else
                            ({{ number_format($c['margin_percent'], 1) }}%)
                        @endif
                    </span>
                    @else
                    -
                    @endif
                </td>
                <td data-label="Branch">
                    @if($item->branch_id)
                    <span style="background:#fde8de;color:#C0392B;padding:0.15rem 0.5rem;border-radius:10px;font-size:0.7rem;font-weight:600;">
                        {{ $item->branch->name ?? 'Branch #'.$item->branch_id }}
                    </span>
                    @else
                    <span style="background:#d4edda;color:#155724;padding:0.15rem 0.5rem;border-radius:10px;font-size:0.7rem;font-weight:600;">
                        All Branches
                    </span>
                    @endif
                </td>
                <td data-label="Available">
                    {{-- "Enable/Disable Menu Items" is Y | Y | N. Staff still
                         need to SEE whether a dish is on sale — that is the
                         View row — so they get the state as a read-only badge
                         instead of the toggle. Hiding the cell entirely would
                         take away information they are entitled to; showing a
                         dead checkbox would invite a click that 302s them back
                         to the dashboard with a permission error. --}}
                    @if($canManageMenu)
                    <form action="{{ route('admin.menu-items.toggle', $item->id) }}" method="POST" style="margin: 0;">
                        @csrf @method('PUT')
                        <input type="checkbox" onchange="this.form.submit()" {{ $item->is_available ? 'checked' : '' }} style="width:16px; height:16px; accent-color:#F4845F; cursor:pointer;">
                    </form>
                    @else
                    <span style="background:{{ $item->is_available ? '#d4edda' : '#f8d7da' }};color:{{ $item->is_available ? '#155724' : '#721c24' }};padding:0.15rem 0.5rem;border-radius:10px;font-size:0.7rem;font-weight:600;">
                        {{ $item->is_available ? 'Available' : 'Unavailable' }}
                    </span>
                    @endif
                </td>
                @if($canManageMenu)
                <td data-label="Edit">
                    <button type="button" class="btn-edit-custom"
                        data-id="{{ $item->id }}"
                        data-name="{{ $item->name }}"
                        data-description="{{ $item->description }}"
                        data-category="{{ $item->category_id }}"
                        data-subcategory="{{ $item->subcategory_id }}"
                        data-price="{{ $item->price }}"
                        {{-- Sized: the Price box turns read-only (starting-from figure). --}}
                        data-has-sizes="{{ $item->allSizes->isNotEmpty() ? '1' : '0' }}"
                        data-cost="{{ $item->cost }}"
                        {{-- Recipe-derived cost. When set, the Cost box in the modal
                             goes read-only: the recipe is the source of truth. --}}
                        data-has-recipe="{{ ($costing[$item->id]['is_fallback'] ?? true) ? '0' : '1' }}"
                        data-computed-cost="{{ number_format($costing[$item->id]['cost'] ?? 0, 2, '.', '') }}"
                        data-inventory="{{ $item->inventory_item_id }}"
                        data-amount-used="{{ $item->inventory_amount_used }}"
                        data-image="{{ $item->image ? \App\Support\Img::url($item->image) : '' }}"
                        data-branch="{{ $item->branch_id ?? '' }}"
                        onclick="openEditModal(this)">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                </td>
                <td data-label="Delete">
                    {{-- The matrix's LIMITED row, per item. A supervisor gets
                         the button only on their OWN branch's items — never on
                         a shared "All Branches" item, and never on another
                         branch's. The cell itself stays so the column does not
                         go ragged; deleteMenuItem() enforces the same rule
                         server-side, which is what actually stops a crafted
                         DELETE. --}}
                    @if($canDeleteItem($item))
                    <button class="btn-danger-custom" data-id="{{ $item->id }}" onclick="confirmDelete(this.dataset.id)">
                        <i class="bi bi-trash3"></i>
                    </button>
                    @endif
                </td>
                @endif
            </tr>
            @endforeach
            @else
            <tr>
                <td colspan="{{ $canManageMenu ? 12 : 10 }}" style="text-align: center; color: #4B5563; font-weight: 500; padding: 2rem;">No menu items yet. Click "Add New Item" to get started!</td>
            </tr>
            @endif
        </tbody>
    </table>
</div>

{{-- The write modals. Gated on $canManageMenu, matching the buttons that open
     them: a staff member has no Add New Item and no Edit control, so shipping
     these forms (and the recipe editor inside the second one) would leave
     hidden POST targets for manager-only routes in their page for nothing. The
     route groups are what refuse a crafted request; this just stops rendering
     the form. --}}
@if($canManageMenu)
{{-- ADD/EDIT MODAL --}}
<div id="itemModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center; padding:1rem;">
    <div style="background:white; border-radius:12px; padding:1.5rem; max-width:600px; width:100%; max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 id="modalTitle" style="font-size:1.1rem; font-weight:700; color:#333; margin:0;">New Menu Item</h3>
            <button onclick="closeModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#888;">&times;</button>
        </div>

        <form id="itemForm" action="{{ route('admin.new-menu-item.post') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">

            <div style="display: flex; gap: 0.75rem; margin-bottom: 0.85rem;">
                <div style="flex: 1;">
                    <label class="form-label-custom">Item Name</label>
                    <input type="text" name="name" id="itemName" class="form-control-custom" value="{{ old('name') }}" required style="margin-bottom:0;" autocomplete="off">
                </div>
            </div>

            <div style="display: flex; gap: 0.75rem; margin-bottom: 0.85rem;">
                <div style="flex: 1;">
                    <label class="form-label-custom">Main Category</label>
                    <select name="category_id" id="itemCategory" class="form-control-custom" required style="margin-bottom:0;">
                        <option value=""> Select </option>
                        @if(isset($categories))
                        @foreach($categories as $category)
                        <option value="{{ $category->id }}" {{ (string) old('category_id') === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                        @endforeach
                        @endif
                    </select>
                </div>
                <div style="flex: 1;">
                    <label class="form-label-custom">Sub Category</label>
                    <select name="subcategory_id" id="itemSubcategory" class="form-control-custom" style="margin-bottom:0;">
                        <option value=""> Select </option>
                        @if(isset($subcategories))
                        @foreach($subcategories as $sub)
                        <option value="{{ $sub->id }}" {{ (string) old('subcategory_id') === (string) $sub->id ? 'selected' : '' }}>{{ $sub->name }}</option>
                        @endforeach
                        @endif
                    </select>
                </div>
            </div>

            {{-- ══════════ RECIPE INGREDIENTS — the heart of the form, so it sits
                 in the middle: after what the item IS, before what it costs.
                 Nothing inside this block is a <form> element — the per-item
                 "add ingredient" control used to be its own nested <form> posted
                 via fetch(); it is now a plain row with a button click handler
                 that does the exact same fetch() call, specifically so this
                 whole section can live inside #itemForm without the browser
                 silently dropping a nested form (nested <form> tags are invalid
                 HTML and get parsed away). The per-row delete buttons were
                 already plain buttons, not forms, so they needed no change.

                 ONE partial (admin/partials/recipe-ingredients.blade.php) renders
                 BOTH the Add-mode block and every Edit-mode block below, so the
                 two can never drift into different layouts again — the only
                 thing that differs between them is the mechanism the "+ Add"
                 button uses (fetch() vs. append-client-side), decided in JS by
                 whether the entry row carries a data-url. --}}
            <div style="border:2px solid #F4845F; border-radius:10px; padding:0.65rem 0.75rem; margin-bottom:0.85rem;">
                <p style="font-size:0.85rem; font-weight:700; color:#C0392B; margin:0 0 0.5rem;">
                    <i class="bi bi-list-ul"></i> Recipe Ingredients
                </p>

                {{-- ── ADD MODE ── --}}
                @php
                    $addDraftRows = [];
                    foreach (old('ingredients', []) as $oldRow) {
                        if (($oldRow['inventory_id'] ?? '') === '' && ($oldRow['quantity_used'] ?? '') === '') {
                            continue;
                        }
                        $addDraftRows[] = $oldRow;
                    }
                    $addDraftCost = 0.0;
                    foreach ($addDraftRows as $draftRow) {
                        $draftInv = ($inventoryItems ?? collect())->firstWhere('id', (int) ($draftRow['inventory_id'] ?? 0));
                        if ($draftInv) {
                            $addDraftCost += (float) ($draftRow['quantity_used'] ?? 0) * (float) $draftInv->unit_cost;
                        }
                    }
                @endphp
                <div class="recipe-block" id="recipe-add" style="display:none;">
                    @include('admin.partials.recipe-ingredients', [
                        'blockId' => 'add',
                        'recipe' => collect(),
                        'draftRows' => $addDraftRows,
                        'inventoryItems' => $inventoryItems ?? collect(),
                        'branchNames' => $branchNames ?? collect(),
                        'addUrl' => null,
                        'costLine' => ['cost' => $addDraftCost, 'is_fallback' => false],
                    ])
                </div>

                {{-- ── EDIT MODE: one block per existing menu item, JS shows the matching one ── --}}
                @if(isset($menuItems))
                    @foreach($menuItems as $mi)
                        <div class="recipe-block" id="recipe-{{ $mi->id }}" style="display:none;">
                            @include('admin.partials.recipe-ingredients', [
                                'blockId' => $mi->id,
                                'recipe' => $mi->recipeIngredients,
                                'draftRows' => [],
                                'inventoryItems' => $inventoryItems ?? collect(),
                                // Under "All Branches" $inventoryItems is every
                                // branch's stock; each item's picker narrows to
                                // ITS branch (see recipe-ingredients.blade.php).
                                'branchId' => $mi->branch_id,
                                'branchNames' => $branchNames ?? collect(),
                                'addUrl' => route('admin.menu-items.ingredients.add', $mi->id),
                                'costLine' => $costing[$mi->id] ?? ['cost' => 0, 'is_fallback' => true],
                            ])
                        </div>
                    @endforeach
                @endif
            </div>
            {{-- Optional details, collapsed — visually secondary now that
                 ingredients lead the form. Same field name, same column, every
                 existing reader untouched. --}}
            <details style="margin-bottom: 0.85rem; background:#f4f4f4; border-radius:8px; padding:0.55rem 0.75rem;" {{ old('description') ? 'open' : '' }}>
                <summary style="cursor:pointer; font-size:0.78rem; font-weight:600; color:#374151;">
                    <i class="bi bi-card-text"></i> Optional details
                </summary>
                <div style="margin-top:0.6rem;">
                    <label class="form-label-custom">Item Description</label>
                    <textarea name="description" id="itemDescription" rows="3" class="form-control-custom"
                        style="resize: none; margin-bottom: 0;">{{ old('description') }}</textarea>
                </div>
            </details>

            <div style="display: flex; gap: 0.75rem; margin-bottom: 0.85rem;">
                <div style="flex: 1;">
                    <label class="form-label-custom">Price (₱)</label>
                    <input type="number" name="price" id="itemPrice" class="form-control-custom" step="0.01" min="0" value="{{ old('price') }}" required style="margin-bottom:0;">
                    <small id="itemPriceNote" style="font-size:0.72rem;color:#4B5563;font-weight:500;display:none;">
                        Starting-from price: the lowest active size. Set prices in Sizes.
                    </small>
                </div>
                <div style="flex: 1;">
                    <label class="form-label-custom">Cost (₱)</label>
                    <input type="number" name="cost" id="itemCost" class="form-control-custom" step="0.01" min="0" value="{{ old('cost') }}" style="margin-bottom:0;">
                    {{-- No directional word ("above"/"below") on purpose — the Cost
                         field's position relative to Recipe Ingredients could change
                         again, and a directional note is the kind of thing that quietly
                         goes stale. --}}
                    <small id="itemCostNote" style="font-size:0.72rem;color:#4B5563;font-weight:500;display:none;">
                        Cost is calculated from the Recipe Ingredients.
                    </small>
                </div>
            </div>

            {{-- ══════════ SIZES (Menu Item Sizes, Phase 1) ══════════
                 Regular and Large only — the names are fixed labels, never a
                 text box, and the server/database refuse anything else. Right
                 under Price because, once an item is sized, THIS is where its
                 prices live and the Price box above becomes the read-only
                 "starting from" figure (lowest active size).

                 Same shape as Recipe Ingredients above: one hidden block per
                 menu item, openEditModal() shows the matching one. Size price /
                 active / archive / restore / set-up are separate saves, so
                 their controls carry form="…" and belong to small forms
                 rendered OUTSIDE #itemForm (after the modal) — a nested <form>
                 would be dropped by the parser, the exact bug the recipe editor
                 comment above describes. Each size's recipe reuses the recipe
                 partial and its fetch() editor unchanged. --}}
            <div class="size-section" id="sizesSection" style="display:none;">
                <p class="size-section-title"><i class="bi bi-cup-hot"></i> Sizes</p>

                <div class="size-block" id="sizes-add" style="display:none;">
                    <p class="size-hint" style="margin:0;">Save the item first — Regular and Large sizes are set up from <strong>Edit</strong>.</p>
                </div>

                @if(isset($menuItems))
                    @foreach($menuItems as $mi)
                    <div class="size-block" id="sizes-{{ $mi->id }}" style="display:none;">
                        @if($mi->allSizes->isEmpty())
                            <p class="size-hint">This item has one price and one recipe. To sell it as <strong>Regular</strong> and <strong>Large</strong> instead, enter both prices — each size then gets its own price and its own recipe.</p>
                            <div class="size-enable-row">
                                <div>
                                    <label class="form-label-custom" for="sizeEnableRegular-{{ $mi->id }}">Regular price (₱)</label>
                                    <input type="number" id="sizeEnableRegular-{{ $mi->id }}" name="regular_price" form="sizeEnable-{{ $mi->id }}" class="form-control-custom" step="0.01" min="0.01" max="99999999.99" required autocomplete="off" style="margin-bottom:0;">
                                </div>
                                <div>
                                    <label class="form-label-custom" for="sizeEnableLarge-{{ $mi->id }}">Large price (₱)</label>
                                    <input type="number" id="sizeEnableLarge-{{ $mi->id }}" name="large_price" form="sizeEnable-{{ $mi->id }}" class="form-control-custom" step="0.01" min="0.01" max="99999999.99" required autocomplete="off" style="margin-bottom:0;">
                                </div>
                                <button type="submit" form="sizeEnable-{{ $mi->id }}" class="btn-primary-custom size-btn">
                                    <i class="bi bi-plus-circle"></i> Set up sizes
                                </button>
                            </div>
                        @else
                            @php $liveSizeCount = $mi->allSizes->filter->isLive()->count(); @endphp
                            <p class="size-hint">
                                @if($liveSizeCount > 0)
                                    Starting from <strong>₱{{ number_format($mi->price, 2) }}</strong> — the lowest active size. A size needs its own recipe before it can be ordered.
                                @else
                                    <span class="size-chip size-chip-warn">No active size</span>
                                    Neither size can be ordered. The list keeps showing ₱{{ number_format($mi->price, 2) }} until a size is active again.
                                @endif
                            </p>

                            @foreach($mi->allSizes as $size)
                                @php $sizeLineCount = $size->ingredients->count(); @endphp
                                <div class="size-card {{ $size->isArchived() ? 'is-archived' : '' }}" id="size-card-{{ $size->id }}">
                                    <div class="size-card-head">
                                        <strong class="size-name">{{ $size->name }}</strong>
                                        @if($size->isArchived())
                                            <span class="size-chip size-chip-muted">Archived</span>
                                        @elseif(! $size->is_active)
                                            <span class="size-chip size-chip-off">Inactive</span>
                                        @else
                                            <span class="size-chip size-chip-on">Active</span>
                                        @endif
                                        <span class="size-chip {{ $sizeLineCount === 0 ? 'size-chip-warn' : 'size-chip-on' }}" id="size-recipe-chip-{{ $size->id }}">
                                            {{ $sizeLineCount === 0 ? 'No Recipe Set' : $sizeLineCount . ' ' . ($sizeLineCount === 1 ? 'ingredient' : 'ingredients') }}
                                        </span>
                                    </div>

                                    @if(! $size->isArchived())
                                        <div class="size-edit-row">
                                            <div class="size-price-field">
                                                <label class="form-label-custom" for="sizePrice-{{ $size->id }}">{{ $size->name }} price (₱)</label>
                                                <input type="number" id="sizePrice-{{ $size->id }}" name="price" form="sizeUpdate-{{ $size->id }}" class="form-control-custom" step="0.01" min="0.01" max="99999999.99" value="{{ $size->price }}" required autocomplete="off" style="margin-bottom:0;">
                                            </div>
                                            <label class="size-active-toggle" for="sizeActive-{{ $size->id }}">
                                                <input type="hidden" name="is_active" value="0" form="sizeUpdate-{{ $size->id }}">
                                                <input type="checkbox" id="sizeActive-{{ $size->id }}" name="is_active" value="1" form="sizeUpdate-{{ $size->id }}" {{ $size->is_active ? 'checked' : '' }}>
                                                Active
                                            </label>
                                            <button type="submit" form="sizeUpdate-{{ $size->id }}" class="btn-primary-custom size-btn">Save {{ $size->name }}</button>
                                            <button type="submit" form="sizeArchive-{{ $size->id }}" class="size-archive-btn"
                                                onclick="return confirm('Archive the {{ $size->name }} size? Its recipe is kept and it can be restored.');">
                                                <i class="bi bi-archive"></i> Archive
                                            </button>
                                        </div>

                                        <div class="size-recipe-block" id="recipe-size-{{ $size->id }}">
                                            <p class="size-recipe-title">{{ $size->name }} recipe <span>— used instead of the base recipe for this size</span></p>
                                            @include('admin.partials.recipe-ingredients', [
                                                'blockId' => 'size-' . $size->id,
                                                'recipe' => $size->ingredients,
                                                'draftRows' => [],
                                                'inventoryItems' => $inventoryItems ?? collect(),
                                                // Narrowed to the PARENT item's branch — a size
                                                // has no branch of its own.
                                                'branchId' => $mi->branch_id,
                                                'branchNames' => $branchNames ?? collect(),
                                                'addUrl' => route('admin.menu-items.sizes.ingredients.add', [$mi->id, $size->id]),
                                                'qtyAttribute' => 'quantity',
                                                'deleteUrlFor' => fn ($row) => route('admin.menu-items.sizes.ingredients.delete', [$mi->id, $size->id, $row->id]),
                                                'costLine' => $sizeCosting[$size->id] ?? ['cost' => 0, 'is_fallback' => true],
                                            ])
                                        </div>
                                    @else
                                        <p class="size-hint" style="margin:0 0 0.4rem;">
                                            Archived {{ $size->archived_at->format('M j, Y') }}. Its price (₱{{ number_format($size->price, 2) }}) and recipe ({{ $sizeLineCount }} {{ $sizeLineCount === 1 ? 'ingredient' : 'ingredients' }}) are kept.
                                        </p>
                                        @if($canRestoreSize)
                                            <button type="submit" form="sizeRestore-{{ $size->id }}" class="btn-primary-custom size-btn">
                                                <i class="bi bi-arrow-counterclockwise"></i> Restore {{ $size->name }}
                                            </button>
                                        @else
                                            <p class="size-hint" style="margin:0;">Only the owner can restore an archived size.</p>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </div>
                    @endforeach
                @endif
            </div>

            {{-- Branch Assignment --}}
            @php
            $currentBranchId = session('selected_branch_id', 'all');
            $currentBranchName = $currentBranchId !== 'all'
            ? (\App\Models\Branch::find($currentBranchId)?->name ?? 'Unknown')
            : 'All Branches';
            @endphp
            <div style="margin-bottom:0.85rem;background:#f0f8ff;border:1px solid #bee5eb;border-radius:8px;padding:0.6rem 0.85rem;">
                <label class="form-label-custom" style="margin-bottom:0.2rem;">Branch Assignment</label>
                <p style="font-size:0.85rem;font-weight:600;color:#F4845F;margin:0;">
                    <i class="bi bi-building"></i> {{ $currentBranchName }}
                </p>
                <input type="hidden" name="branch_id" id="itemBranch" value="{{ $currentBranchId !== 'all' ? $currentBranchId : '' }}">
                <small style="font-size:0.72rem;color:#374151;font-weight:500;">Item will be assigned to the currently selected branch.</small>
            </div>

            {{-- Legacy Single-Ingredient Link (collapsed). Kept only so old menu items
                 created before the Recipe Ingredients feature keep working. --}}
            <details style="margin-bottom: 0.85rem; background:#f4f4f4; border-radius:8px; padding:0.55rem 0.75rem;">
                <summary style="cursor:pointer; font-size:0.78rem; font-weight:600; color:#374151;">
                    <i class="bi bi-archive"></i> Legacy Single-Ingredient Link (only used if no Recipe Ingredients are set)
                </summary>
                <p style="font-size:0.72rem; color:#4B5563; font-weight:500; margin:0.5rem 0;">
                    Leave empty for new items — use the <strong>Recipe Ingredients</strong> section above instead.
                </p>
                <div style="display: flex; gap: 0.75rem;">
                    <div style="flex: 1;">
                        <label class="form-label-custom">Inventory Item</label>
                        <select name="inventory_item_id" id="itemInventory" class="form-control-custom" style="margin-bottom:0;">
                            <option value="">-- No link --</option>
                            @if(isset($inventoryItems) && count($inventoryItems) > 0)
                                @foreach($inventoryItems as $inv)
                                    <option value="{{ $inv->id }}" {{ (string) old('inventory_item_id') === (string) $inv->id ? 'selected' : '' }}>{{ $inv->item_name }} ({{ $inv->quantity }} {{ $inv->unit }})</option>
                                @endforeach
                            @else
                                <option value="" disabled>No inventory for this branch yet</option>
                            @endif
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label-custom">Amount Used per Order</label>
                        <input type="number" name="inventory_amount_used" id="itemAmountUsed" class="form-control-custom" step="0.01" min="0" value="{{ old('inventory_amount_used', 0) }}" style="margin-bottom:0;">
                    </div>
                </div>
            </details>

            <div id="currentImageWrapper" style="display:none; margin-bottom: 0.85rem;">
                <label class="form-label-custom">Current Image</label>
                <div>
                    <img id="currentImage" src="" style="width: 80px; height: 80px; border-radius: 8px; object-fit: cover; border: 1px solid #ddd;">
                </div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label class="form-label-custom" id="imageLabel">Item Image </label>
                <input type="file" name="image" class="form-control-custom" accept="image/*" style="margin-bottom:0; padding: 0.45rem 0.85rem;">
                <small style="color: #374151; font-size: 0.72rem; font-weight: 500;">JPG, PNG, or WEBP. Max 2MB.</small>
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn-primary-custom" id="submitBtn" style="flex: 1; padding: 0.65rem; font-size: 0.88rem;">
                    Add Item
                </button>
                <button type="button" onclick="closeModal()" class="btn-danger-custom" style="padding: 0.65rem 1.5rem; font-size: 0.88rem;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Size forms (Menu Item Sizes, Phase 1). Empty on purpose: their fields live
     in the Sizes section inside #itemForm and join these by form="…". One
     small form per record action, so a size's inputs can only ever post to
     that size's own endpoint. Outside #itemForm because nested forms are
     invalid HTML. --}}
<div hidden>
    @if(isset($menuItems))
        @foreach($menuItems as $mi)
            @if($mi->allSizes->isEmpty())
                <form id="sizeEnable-{{ $mi->id }}" method="POST" action="{{ route('admin.menu-items.sizes.enable', $mi->id) }}">@csrf</form>
            @else
                @foreach($mi->allSizes as $size)
                    @if(! $size->isArchived())
                        <form id="sizeUpdate-{{ $size->id }}" method="POST" action="{{ route('admin.menu-items.sizes.update', [$mi->id, $size->id]) }}">@csrf @method('PUT')</form>
                        <form id="sizeArchive-{{ $size->id }}" method="POST" action="{{ route('admin.menu-items.sizes.archive', [$mi->id, $size->id]) }}">@csrf @method('DELETE')</form>
                    @elseif($canRestoreSize)
                        <form id="sizeRestore-{{ $size->id }}" method="POST" action="{{ route('admin.menu-items.sizes.restore', [$mi->id, $size->id]) }}">@csrf</form>
                    @endif
                @endforeach
            @endif
        @endforeach
    @endif
</div>

{{-- Delete Confirmation Modal --}}
<div id="deleteModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; padding:2rem; max-width:320px; width:90%; text-align:center;">
        <p style="font-size:0.88rem; font-weight:600; color:#333; margin-bottom:0.5rem;">Are you sure you want to delete this menu item?</p>
        {{-- Delete always archives now (CatalogueLifecycle::removeMenuItem()). --}}
        <p style="font-size:0.78rem; color:#6b7280; margin-bottom:1.5rem;">It moves to Archived Items, where it can be restored.</p>
        <div style="display:flex; gap:0.75rem; justify-content:center;">
            <form id="deleteForm" method="POST">
                @csrf @method('DELETE')
                <button type="submit" style="background:#F4845F; color:white; border:none; border-radius:8px; padding:0.5rem 1.5rem; font-weight:600; font-size:0.85rem; cursor:pointer; font-family:'Poppins',sans-serif;">Yes</button>
            </form>
            <button onclick="closeDeleteModal()" style="background:#C0392B; color:white; border:none; border-radius:8px; padding:0.5rem 1.5rem; font-weight:600; font-size:0.85rem; cursor:pointer; font-family:'Poppins',sans-serif;">No</button>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
{{-- The whole modal script — openAddModal/openEditModal, the recipe editor
     wiring and the delete confirmation. All of it drives controls only a
     manager has, and every element it reaches for lives inside the gated
     modals above, so for staff it would be dead code that still names
     manager-only endpoints in the page source. --}}
@if($canManageMenu)
<script>
    // Both Add and Edit share this one modal. openAddModal()/openEditModal()
    // decide which state it is in: title, submit label, form action/method,
    // and which Recipe Ingredients block is visible (showRecipeBlock('add') for
    // a new item, showRecipeBlock(id) for an existing one).

    // Open Add Modal — clears every field, including any leftover ingredient
    // rows, so a previous Edit can never leak values into a new item.
    function openAddModal() {
        prepareAddModalChrome();
        document.getElementById('itemName').value = '';
        document.getElementById('itemCategory').value = '';
        document.getElementById('itemSubcategory').value = '';
        document.getElementById('itemDescription').value = '';
        document.getElementById('itemPrice').value = '';
        document.getElementById('itemCost').value = '';
        document.getElementById('itemInventory').value = '';
        document.getElementById('itemAmountUsed').value = '0';
        document.getElementById('currentImageWrapper').style.display = 'none';
        document.getElementById('imageLabel').innerText = 'Item Image (Optional)';
        applyCostLock(false, '');
        resetAddModeIngredientRows();
        document.getElementById('itemModal').style.display = 'flex';
    }

    // The title/button/form-target/visible-recipe-block side of Add mode, split
    // out from openAddModal() so a failed submission can restore this same
    // chrome WITHOUT wiping the admin's typed values — those come back from
    // old() in the server-rendered HTML instead.
    function prepareAddModalChrome() {
        document.getElementById('modalTitle').innerText = 'New Menu Item';
        document.getElementById('submitBtn').innerText = 'Add Item';
        document.getElementById('itemForm').action = `{{ route('admin.new-menu-item.post') }}`;
        document.getElementById('formMethod').value = 'POST';
        showRecipeBlock('add');
        applyPriceLock(false);
        showSizeBlock('add');
    }

    // Open Edit Modal
    function openEditModal(btn) {
        const id = btn.dataset.id;
        document.getElementById('modalTitle').innerText = 'Edit Menu Item';
        document.getElementById('submitBtn').innerText = 'Update Item';
        document.getElementById('itemForm').action = `/admin/menu-items/${id}`;
        document.getElementById('formMethod').value = 'PUT';
        document.getElementById('itemName').value = btn.dataset.name;
        document.getElementById('itemCategory').value = btn.dataset.category;
        document.getElementById('itemSubcategory').value = btn.dataset.subcategory || '';
        document.getElementById('itemDescription').value = btn.dataset.description || '';
        document.getElementById('itemPrice').value = btn.dataset.price;
        applyPriceLock(btn.dataset.hasSizes === '1');
        document.getElementById('itemCost').value = btn.dataset.cost || '';
        applyCostLock(btn.dataset.hasRecipe === '1', btn.dataset.computedCost || '');
        document.getElementById('itemInventory').value = btn.dataset.inventory || '';
        document.getElementById('itemAmountUsed').value = btn.dataset.amountUsed || '0';
        document.getElementById('itemBranch').value = btn.dataset.branch || '';

        if (btn.dataset.image) {
            document.getElementById('currentImage').src = btn.dataset.image;
            document.getElementById('currentImageWrapper').style.display = 'block';
            document.getElementById('imageLabel').innerText = 'Replace Image (Optional)';
        } else {
            document.getElementById('currentImageWrapper').style.display = 'none';
            document.getElementById('imageLabel').innerText = 'Item Image (Optional)';
        }
        showRecipeBlock(id);
        showSizeBlock(id);
        document.getElementById('itemModal').style.display = 'flex';
    }

    // The manual Cost box is a fallback only. When the item has a recipe (or the
    // legacy single-ingredient link), the recipe is the source of truth, so the
    // box shows the computed figure and is locked. The field is NOT removed and
    // the column is NOT dropped — a read-only input still posts nothing new, and
    // the stored value stays exactly as it was.
    // Grey read-only styling matches the read-only fields on the Account screen.
    function applyCostLock(hasRecipe, computedCost) {
        var input = document.getElementById('itemCost');
        var note = document.getElementById('itemCostNote');
        if (hasRecipe) {
            input.value = computedCost;
            input.readOnly = true;
            input.style.background = '#f0f0f0';
            note.style.display = 'block';
        } else {
            input.readOnly = false;
            input.style.background = '';
            note.style.display = 'none';
        }
    }

    // Show only the recipe block for the given menu item id, or 'add' for the
    // draft-row block used when creating a new item.
    function showRecipeBlock(id) {
        document.querySelectorAll('.recipe-block').forEach(function (b) { b.style.display = 'none'; });
        var block = document.getElementById('recipe-' + id);
        if (block) block.style.display = 'block';
    }

    // ══════════ SIZES (Menu Item Sizes, Phase 1) ══════════
    // Same one-block-per-item scheme as the recipe blocks: 'add' shows the
    // "save first" hint, an id shows that item's Regular/Large cards. Each
    // size recipe inside is a .size-recipe-block (NOT .recipe-block, so
    // showRecipeBlock() never hides it); its cost/profit line is filled in
    // from the rows on screen as it is shown.
    function showSizeBlock(id) {
        document.querySelectorAll('.size-block').forEach(function (b) { b.style.display = 'none'; });
        var section = document.getElementById('sizesSection');
        var block = document.getElementById('sizes-' + id);
        if (section) section.style.display = block ? 'block' : 'none';
        if (!block) return;
        block.style.display = 'block';
        block.querySelectorAll('.size-recipe-block').forEach(function (r) {
            recalcRecipeCost(r.id.replace('recipe-', ''));
        });
    }

    // A sized item's Price box is the derived "starting from" figure (lowest
    // active size). Read-only, like the Cost box under a recipe; the server
    // ignores whatever it posts for a sized item anyway (updateMenuItem()).
    function applyPriceLock(isSized) {
        var input = document.getElementById('itemPrice');
        var note = document.getElementById('itemPriceNote');
        if (!input) return;
        input.readOnly = isSized;
        input.style.background = isSized ? '#f0f0f0' : '';
        if (note) note.style.display = isSized ? 'block' : 'none';
    }

    // Keeps a size card's recipe badge in step with its rows after an
    // ingredient is added or removed without a reload — the badge is the
    // "No Recipe Set" signal, so it must never go stale.
    function syncSizeRecipeChip(blockId) {
        var sizeId = String(blockId).slice(5);
        var chip = document.getElementById('size-recipe-chip-' + sizeId);
        var tbody = document.getElementById('recipe-tbody-' + blockId);
        if (!chip || !tbody) return;
        var n = tbody.querySelectorAll('tr[data-ingredient-id]').length;
        chip.textContent = n === 0 ? 'No Recipe Set' : n + (n === 1 ? ' ingredient' : ' ingredients');
        chip.className = 'size-chip ' + (n === 0 ? 'size-chip-warn' : 'size-chip-on');
    }

    document.addEventListener('input', function (e) {
        if (e.target.matches && e.target.matches('input[id^="sizePrice-"]')) {
            recalcRecipeCost('size-' + e.target.id.slice('sizePrice-'.length));
        }
    });

    // After any size save/archive/restore/set-up the server redirects here
    // with the item's id, so the admin lands back in that item's editor at
    // the Sizes section instead of on a closed modal.
    var reopenMenuItemId = "{{ session('menu_item_editing') ? (int) session('menu_item_editing') : '' }}";

    // ══════════ RECIPE INGREDIENTS — ONE set of handlers for both modes ══════════
    // Every entry row (.recipe-ing-add-row) looks and behaves the same in Add
    // and Edit. The only branch is inside the "+ Add" click handler: a
    // data-url on the row means Edit (fetch() persists to the real endpoint);
    // no data-url means Add (a row is appended client-side, carrying hidden
    // ingredients[i][...] inputs, saved with the rest of the item in one POST).

    // Show the chosen ingredient's unit next to the "Quantity Used" label so the
    // admin knows what they are typing in. No stock text or badges — the entry
    // row stays clean.
    document.addEventListener('change', function (e) {
        if (!e.target.classList || !e.target.classList.contains('recipe-ing-select')) return;
        var select = e.target;
        var wrap = select.closest('.recipe-ing-add-row');
        if (!wrap) return;
        var opt = select.options[select.selectedIndex];
        var unit = opt ? (opt.dataset.unit || '') : '';

        var unitLabel = wrap.querySelector('.recipe-unit-label');
        if (unitLabel) unitLabel.textContent = unit ? '(' + unit + ')' : '';
    });

    // Close Modal
    function closeModal() {
        document.getElementById('itemModal').style.display = 'none';
    }

    // Delete confirmation
    function confirmDelete(id) {
        document.getElementById('deleteForm').action = `/admin/menu-items/${id}`;
        document.getElementById('deleteModal').style.display = 'flex';
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
    }

    // Search
    // Shared by searchTable()/filterTable(): applies `matchFn` to every ITEM
    // row (never a .menu-branch-group-row header itself — its own text is
    // just a branch name, which would fail almost any search/category
    // match), then shows a branch section header only while at least one of
    // its own items is still visible, so filtering can never leave an item
    // row stranded under no heading, or a heading floating over zero items.
    function applyMenuRowVisibility(matchFn) {
        const rows = document.querySelectorAll('#menuTable tbody tr');
        const visibleGroups = new Set();
        rows.forEach(row => {
            if (row.classList.contains('menu-branch-group-row')) return;
            const visible = matchFn(row);
            row.style.display = visible ? '' : 'none';
            if (visible) visibleGroups.add(row.dataset.branchGroup);
        });
        rows.forEach(row => {
            if (!row.classList.contains('menu-branch-group-row')) return;
            row.style.display = visibleGroups.has(row.dataset.branchGroup) ? '' : 'none';
        });
    }

    function searchTable() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        applyMenuRowVisibility(row => row.innerText.toLowerCase().includes(input));
    }

    // Filter
    function filterTable() {
        const cat = document.getElementById('categoryFilter').value.toLowerCase();
        const sub = document.getElementById('subCategoryFilter').value.toLowerCase();
        applyMenuRowVisibility(row => {
            const text = row.innerText.toLowerCase();
            const catMatch = cat === '' || text.includes(cat);
            const subMatch = sub === '' || text.includes(sub);
            return catMatch && subMatch;
        });
    }

    // Auto-show modal if errors (sticky form). A failed CREATE (the only path
    // that posts ingredient rows) reopens in Add-mode chrome WITHOUT clearing
    // fields, since the server already re-rendered them from old() — clearing
    // here would throw away exactly the work this feature exists to keep. A
    // failed UPDATE has no server-side field repopulation (pre-existing; the
    // modal has only ever been populated from the Edit button's data-*
    // attributes, which are not available after a redirect) — unchanged.
    var hasErrors = "{{ $errors->any() ? '1' : '0' }}";
    var wasEditSubmission = "{{ old('_method') === 'PUT' ? '1' : '0' }}";
    if (hasErrors === '1') {
        document.addEventListener('DOMContentLoaded', function () {
            if (wasEditSubmission !== '1') {
                prepareAddModalChrome();
                recalcAddModeCost();
            }
            document.getElementById('itemModal').style.display = 'flex';
        });
    }

    // Size action redirect (see reopenMenuItemId above). Never competes with
    // the failed Add/Edit reopen just above — size refusals arrive as the
    // page's error flash, not the error bag.
    if (reopenMenuItemId && hasErrors !== '1') {
        document.addEventListener('DOMContentLoaded', function () {
            var editBtn = document.querySelector('.btn-edit-custom[data-id="' + reopenMenuItemId + '"]');
            if (!editBtn) return;
            openEditModal(editBtn);
            var section = document.getElementById('sizesSection');
            if (section && section.scrollIntoView) section.scrollIntoView({ block: 'start' });
        });
    }

    // ══════════ Live "Cost from recipe" — ONE function for both Add and Edit ══════════
    // Recomputes the running total straight from the rows the table is showing,
    // for whichever Recipe Ingredients block is named ('add' for a new item, a
    // menu item's id for Edit). Deliberately the SAME arithmetic as
    // App\Services\MenuItemCosting: sum(quantity_used x unit_cost). It is only a
    // preview — the server recomputes from the saved rows on submit / on each
    // add-ingredient POST and never trusts this number.
    //
    // Every costed row (a draft row in Add, a saved row in Edit, and the row JS
    // appends after an Edit add-ingredient succeeds) carries data-unit-cost and
    // data-qty; Add-mode draft rows also keep the authoritative quantity in a
    // hidden input, which wins while it is being typed.
    //
    // A size recipe block ('size-<id>') is costed the same way but is NOT the
    // item's recipe: it never locks the item's Cost box, and its profit is
    // against that size's own price box, not the item's Price.
    function recalcRecipeCost(blockId) {
        var tbody = document.getElementById('recipe-tbody-' + blockId);
        if (!tbody) return;
        var isSizeBlock = String(blockId).indexOf('size-') === 0;

        var total = 0;
        var filled = 0;
        tbody.querySelectorAll('tr[data-unit-cost]').forEach(function (row) {
            var unitCost = parseFloat(row.dataset.unitCost || '0');
            var amount = parseFloat(row.dataset.qty || '0');
            var qtyInput = row.querySelector('input[name$="[quantity_used]"]');
            if (qtyInput) amount = parseFloat(qtyInput.value || '0');
            filled++;
            if (!isNaN(unitCost) && !isNaN(amount)) total += unitCost * amount;
        });

        var totalEl = document.getElementById('recipe-cost-' + blockId);
        var profitEl = document.getElementById('recipe-profit-' + blockId);
        if (totalEl) totalEl.textContent = '₱' + total.toFixed(2);

        if (isSizeBlock) {
            syncSizeRecipeChip(blockId);
        } else {
            applyCostLock(filled > 0, total.toFixed(2));
        }

        var priceInput = isSizeBlock
            ? document.getElementById('sizePrice-' + String(blockId).slice(5))
            : document.getElementById('itemPrice');
        var price = parseFloat(priceInput && priceInput.value ? priceInput.value : '0');
        if (profitEl) {
            if (filled > 0 && price > 0) {
                var profit = price - total;
                var sign = profit < 0 ? '-₱' : '₱';
                profitEl.textContent = ' · Profit ' + sign + Math.abs(profit).toFixed(2)
                    + ' (' + (profit / price * 100).toFixed(1) + '%)';
                profitEl.style.color = profit < 0 ? '#C0392B' : '#155724';
            } else {
                profitEl.textContent = '';
            }
        }
    }

    // Add mode keeps its own name for the existing call sites (draft add/remove,
    // quantity input, price input, the failed-submit re-open).
    function recalcAddModeCost() { recalcRecipeCost('add'); }
    window.riRecalc = recalcAddModeCost;

    // Draft rows use an ever-increasing index (never reused), so removing one
    // can never collide two rows onto the same ingredients[i] key.
    var addModeNextIndex = (function () {
        var tbody = document.getElementById('recipe-tbody-add');
        return tbody ? tbody.querySelectorAll('[data-draft-row]').length : 0;
    })();

    function toggleAddModeTableVisibility() {
        var tbody = document.getElementById('recipe-tbody-add');
        var hasRows = tbody && tbody.children.length > 0;
        var empty = document.getElementById('recipe-empty-add');
        var table = document.getElementById('recipe-table-add');
        if (empty) empty.style.display = hasRows ? 'none' : 'block';
        if (table) table.style.display = hasRows ? '' : 'none';
    }

    // Clears every draft row — called when the modal opens fresh for a new item,
    // so a previous Edit (or a previous Add attempt) can never leak rows in.
    window.resetAddModeIngredientRows = function () {
        var tbody = document.getElementById('recipe-tbody-add');
        if (tbody) tbody.innerHTML = '';
        addModeNextIndex = 0;
        toggleAddModeTableVisibility();
        recalcAddModeCost();
    };

    document.addEventListener('input', function (e) {
        if (e.target.matches && e.target.matches('#recipe-tbody-add input[name$="[quantity_used]"]')) {
            recalcAddModeCost();
        }
    });

    var itemPriceInput = document.getElementById('itemPrice');
    if (itemPriceInput) {
        itemPriceInput.addEventListener('input', function () {
            var addBlock = document.getElementById('recipe-add');
            if (addBlock && addBlock.style.display !== 'none') recalcAddModeCost();
        });
    }

    // ══════════ "+ Add" — fetch() in Edit, append client-side in Add ══════════
    function recipeIngredientError(blockId, message) {
        var box = document.getElementById('recipe-error-' + blockId);
        if (!box) return;
        box.textContent = message;
        box.style.display = 'block';
    }

    function recipeIngredientClearError(blockId) {
        var box = document.getElementById('recipe-error-' + blockId);
        if (box) box.style.display = 'none';
    }

    document.addEventListener('click', function (e) {
        var addBtn = e.target.closest ? e.target.closest('.recipe-ing-add-btn') : null;
        if (!addBtn) return;

        var wrap = addBtn.closest('.recipe-ing-add-row');
        var blockId = wrap.dataset.block;
        var select = wrap.querySelector('.recipe-ing-select');
        var qtyInput = wrap.querySelector('.recipe-ing-qty');

        if (!select.value || !qtyInput.value) {
            recipeIngredientError(blockId, 'Choose an ingredient and enter a quantity.');
            return;
        }

        // Refuse an inventory item that is already a row on this recipe, at the
        // moment of adding — before a draft row is appended (Add) or anything is
        // posted (Edit). The server re-checks either way: storeNewMenuItem()
        // rejects a duplicate at submit and addIngredient() rejects a duplicate
        // POST, both with their own message. This is only so the admin hears it
        // now instead of after filling the whole form.
        var dupTbody = document.getElementById('recipe-tbody-' + blockId);
        var alreadyListed = wrap.dataset.url
            ? !!(dupTbody && dupTbody.querySelector('tr[data-inventory-id="' + select.value + '"]'))
            : !!(dupTbody && dupTbody.querySelector('input[name$="[inventory_id]"][value="' + select.value + '"]'));
        if (alreadyListed) {
            var dupOpt = select.options[select.selectedIndex];
            var dupName = (dupOpt.dataset.name
                || (dupOpt.textContent || 'That ingredient').replace(/\s*\([^)]*\)\s*$/, '')).trim();
            recipeIngredientError(blockId, dupName + ' is already in the recipe.');
            return;
        }

        recipeIngredientClearError(blockId);

        function resetEntryRow() {
            select.value = '';
            qtyInput.value = '';
            var unitLabel = wrap.querySelector('.recipe-unit-label');
            if (unitLabel) unitLabel.textContent = '';
        }

        if (!wrap.dataset.url) {
            // ── ADD MODE — no server round-trip yet. Append a row that looks
            // exactly like a saved one, with the real ingredients[i][...]
            // values riding along as hidden inputs inside it.
            var opt = select.options[select.selectedIndex];
            var unit = opt.dataset.unit || '';
            // data-name, not the option's text: the text now ends in the branch
            // label ("Cheese (kg) — Main Branch"), which the old strip-a-
            // trailing-"(unit)" regex would have carried into the row name.
            var name = (opt.dataset.name
                || opt.textContent.replace(/\s*\([^)]*\)\s*$/, '')).trim();
            var idx = addModeNextIndex++;

            var row = document.createElement('tr');
            row.style.borderTop = '1px solid #f0f0f0';
            row.dataset.draftRow = idx;
            row.dataset.unitCost = opt.dataset.cost || '0';
            row.innerHTML =
                '<td style="padding:0.35rem 0.4rem;"></td>' +
                '<td style="padding:0.35rem 0.4rem;"></td>' +
                '<td style="padding:0.35rem 0.4rem; text-align:right;">' +
                '<button type="button" class="recipe-ing-delete-btn" data-draft="1" ' +
                'style="background:#C0392B; color:white; border:none; border-radius:6px; padding:0.2rem 0.5rem; font-size:0.7rem; cursor:pointer;">' +
                '<i class="bi bi-trash3"></i></button>' +
                '</td>';
            row.children[0].textContent = name;
            row.children[1].textContent = qtyInput.value + (unit ? ' ' + unit : '');

            // Phase 3b F11: built via createElement + .value, like the two
            // cells above, rather than concatenated into the innerHTML string
            // — select.value / qtyInput.value are numeric-only in practice
            // (a <select> of inventory ids, a <input type="number">), but
            // interpolating them into value="..." was still a self-XSS
            // attribute-breakout for anyone editing their own DOM.
            var inventoryIdInput = document.createElement('input');
            inventoryIdInput.type = 'hidden';
            inventoryIdInput.name = 'ingredients[' + idx + '][inventory_id]';
            inventoryIdInput.value = select.value;
            row.children[2].appendChild(inventoryIdInput);

            var quantityUsedInput = document.createElement('input');
            quantityUsedInput.type = 'hidden';
            quantityUsedInput.name = 'ingredients[' + idx + '][quantity_used]';
            quantityUsedInput.value = qtyInput.value;
            row.children[2].appendChild(quantityUsedInput);

            document.getElementById('recipe-tbody-add').appendChild(row);
            toggleAddModeTableVisibility();
            resetEntryRow();
            recalcAddModeCost();
            return;
        }

        // ── EDIT MODE — persist via fetch().
        addBtn.disabled = true;

        // The picker option carries the same per-unit cost the live preview
        // computes from (data-cost); keep it so the appended row can feed
        // recalcRecipeCost() exactly like a draft row does in Add mode.
        var editOpt = select.options[select.selectedIndex];
        var editUnitCost = (editOpt && editOpt.dataset.cost) || '0';
        var editQty = qtyInput.value;

        var token = document.querySelector('#itemForm input[name="_token"]').value;
        var fd = new FormData();
        fd.append('inventory_id', select.value);
        fd.append('quantity_used', qtyInput.value);

        fetch(wrap.dataset.url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: fd,
        })
            .then(function (res) { return res.json().then(function (data) { return { status: res.status, data: data }; }); })
            .then(function (result) {
                addBtn.disabled = false;

                if (result.status >= 400 || !result.data.success) {
                    var message = result.data.message
                        || (result.data.errors && Object.values(result.data.errors)[0][0])
                        || 'Could not add ingredient.';
                    recipeIngredientError(blockId, message);
                    return;
                }

                var ing = result.data.ingredient;

                var tbody = document.getElementById('recipe-tbody-' + blockId);
                var row = document.createElement('tr');
                row.style.borderTop = '1px solid #f0f0f0';
                row.dataset.ingredientId = ing.id;
                if (ing.inventory_id) row.dataset.inventoryId = ing.inventory_id;
                row.dataset.unitCost = editUnitCost;
                row.dataset.qty = editQty;
                row.innerHTML =
                    '<td style="padding:0.35rem 0.4rem;"></td>' +
                    '<td style="padding:0.35rem 0.4rem;"></td>' +
                    '<td style="padding:0.35rem 0.4rem; text-align:right;">' +
                    '<button type="button" class="recipe-ing-delete-btn" ' +
                    'style="background:#C0392B; color:white; border:none; border-radius:6px; padding:0.2rem 0.5rem; font-size:0.7rem; cursor:pointer;">' +
                    '<i class="bi bi-trash3"></i></button></td>';
                row.children[0].textContent = ing.name;
                row.children[1].textContent = ing.quantity_used + (ing.unit ? ' ' + ing.unit : '');
                // Property, not string-concatenated markup — as the draft-row
                // inputs above already are (Phase 3b F11).
                row.querySelector('.recipe-ing-delete-btn').dataset.url = ing.delete_url;
                tbody.appendChild(row);

                document.getElementById('recipe-empty-' + blockId).style.display = 'none';
                document.getElementById('recipe-table-' + blockId).style.display = '';

                resetEntryRow();
                recalcRecipeCost(blockId);
            })
            .catch(function () {
                addBtn.disabled = false;
                recipeIngredientError(blockId, 'Network error — could not add ingredient.');
            });
    });

    // ══════════ Delete — client-side for a draft row, fetch() for a saved one ══════════
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.recipe-ing-delete-btn') : null;
        if (!btn) return;

        if (btn.dataset.draft === '1') {
            var draftRow = btn.closest('tr');
            if (draftRow) draftRow.remove();
            toggleAddModeTableVisibility();
            recalcAddModeCost();
            return;
        }

        if (!confirm('Remove this ingredient?')) return;

        var row = btn.closest('tr');
        // .size-recipe-block too: a size recipe's wrapper is "recipe-size-<id>",
        // so its blockId comes out as "size-<id>", matching its partial ids.
        var block = btn.closest('.recipe-block, .size-recipe-block');
        var blockId = block ? block.id.replace('recipe-', '') : null;
        var token = document.querySelector('#itemForm input[name="_token"]').value;

        fetch(btn.dataset.url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: (function () {
                var fd = new FormData();
                fd.append('_method', 'DELETE');
                return fd;
            })(),
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) return;
                if (row) row.remove();

                if (blockId) {
                    var tbody = document.getElementById('recipe-tbody-' + blockId);
                    if (tbody && tbody.children.length === 0) {
                        document.getElementById('recipe-empty-' + blockId).style.display = 'block';
                        document.getElementById('recipe-table-' + blockId).style.display = 'none';
                    }
                    recalcRecipeCost(blockId);
                }
            });
    });
</script>
@endif
@endpush

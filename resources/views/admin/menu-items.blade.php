@extends('admin.layout')

@section('title', 'Menu Items - Peachy Admin')

@section('content')

@php
    $adminUser = Auth::guard('admin')->user();
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
        @if($adminUser && $adminUser->role === 'admin')
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
                @if($adminUser && $adminUser->role === 'admin')
                <th>Edit</th>
                <th>Delete</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @if(isset($menuItems) && count($menuItems) > 0)
            @foreach($menuItems as $item)
            <tr>
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
                <td data-label="Price" style="font-weight: 600;">₱{{ number_format($item->price, 2) }}</td>
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
                    <form action="{{ route('admin.menu-items.toggle', $item->id) }}" method="POST" style="margin: 0;">
                        @csrf @method('PUT')
                        <input type="checkbox" onchange="this.form.submit()" {{ $item->is_available ? 'checked' : '' }} style="width:16px; height:16px; accent-color:#F4845F; cursor:pointer;">
                    </form>
                </td>
                @if($adminUser && $adminUser->role === 'admin')
                <td data-label="Edit">
                    <button type="button" class="btn-edit-custom"
                        data-id="{{ $item->id }}"
                        data-name="{{ $item->name }}"
                        data-description="{{ $item->description }}"
                        data-category="{{ $item->category_id }}"
                        data-subcategory="{{ $item->subcategory_id }}"
                        data-price="{{ $item->price }}"
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
                    <button class="btn-danger-custom" data-id="{{ $item->id }}" onclick="confirmDelete(this.dataset.id)">
                        <i class="bi bi-trash3"></i>
                    </button>
                </td>
                @endif
            </tr>
            @endforeach
            @else
            <tr>
                <td colspan="11" style="text-align: center; color: #4B5563; font-weight: 500; padding: 2rem;">No menu items yet. Click "Add New Item" to get started!</td>
            </tr>
            @endif
        </tbody>
    </table>
</div>

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

{{-- Delete Confirmation Modal --}}
<div id="deleteModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; padding:2rem; max-width:320px; width:90%; text-align:center;">
        <p style="font-size:0.88rem; font-weight:600; color:#333; margin-bottom:1.5rem;">Are you sure you want to delete this menu item?</p>
        <div style="display:flex; gap:0.75rem; justify-content:center;">
            <form id="deleteForm" method="POST">
                @csrf @method('DELETE')
                <button type="submit" style="background:#F4845F; color:white; border:none; border-radius:8px; padding:0.5rem 1.5rem; font-weight:600; font-size:0.85rem; cursor:pointer; font-family:'Poppins',sans-serif;">Yes</button>
            </form>
            <button onclick="closeDeleteModal()" style="background:#C0392B; color:white; border:none; border-radius:8px; padding:0.5rem 1.5rem; font-weight:600; font-size:0.85rem; cursor:pointer; font-family:'Poppins',sans-serif;">No</button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
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
    function searchTable() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#menuTable tbody tr');
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
        });
    }

    // Filter
    function filterTable() {
        const cat = document.getElementById('categoryFilter').value.toLowerCase();
        const sub = document.getElementById('subCategoryFilter').value.toLowerCase();
        const rows = document.querySelectorAll('#menuTable tbody tr');
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            const catMatch = cat === '' || text.includes(cat);
            const subMatch = sub === '' || text.includes(sub);
            row.style.display = catMatch && subMatch ? '' : 'none';
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

    // ══════════ ADD MODE: recompute the running total from the table's own rows ══════════
    // Runs after every add/remove of a draft row. Deliberately the SAME
    // arithmetic as App\Services\MenuItemCosting: sum(quantity_used x
    // unit_cost). It is only a preview — storeNewMenuItem() recomputes from
    // the saved rows and never trusts this number.
    function recalcAddModeCost() {
        var tbody = document.getElementById('recipe-tbody-add');
        if (!tbody) return;

        var total = 0;
        var filled = 0;
        tbody.querySelectorAll('tr[data-draft-row]').forEach(function (row) {
            var qtyInput = row.querySelector('input[name$="[quantity_used]"]');
            if (!qtyInput) return;
            filled++;
            var unitCost = parseFloat(row.dataset.unitCost || '0');
            var amount = parseFloat(qtyInput.value || '0');
            if (!isNaN(unitCost) && !isNaN(amount)) total += unitCost * amount;
        });

        var totalEl = document.getElementById('recipe-cost-add');
        var profitEl = document.getElementById('recipe-profit-add');
        if (totalEl) totalEl.textContent = '₱' + total.toFixed(2);

        applyCostLock(filled > 0, total.toFixed(2));

        var priceInput = document.getElementById('itemPrice');
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
            var dupName = (select.options[select.selectedIndex].textContent || 'That ingredient')
                .replace(/\s*\([^)]*\)\s*$/, '').trim();
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
            var name = opt.textContent.replace(/\s*\([^)]*\)\s*$/, '').trim();
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
                '<input type="hidden" name="ingredients[' + idx + '][inventory_id]" value="' + select.value + '">' +
                '<input type="hidden" name="ingredients[' + idx + '][quantity_used]" value="' + qtyInput.value + '">' +
                '</td>';
            row.children[0].textContent = name;
            row.children[1].textContent = qtyInput.value + (unit ? ' ' + unit : '');

            document.getElementById('recipe-tbody-add').appendChild(row);
            toggleAddModeTableVisibility();
            resetEntryRow();
            recalcAddModeCost();
            return;
        }

        // ── EDIT MODE — persist via fetch(), unchanged from before.
        addBtn.disabled = true;

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
                row.innerHTML =
                    '<td style="padding:0.35rem 0.4rem;"></td>' +
                    '<td style="padding:0.35rem 0.4rem;"></td>' +
                    '<td style="padding:0.35rem 0.4rem; text-align:right;">' +
                    '<button type="button" class="recipe-ing-delete-btn" data-url="' + ing.delete_url + '" ' +
                    'style="background:#C0392B; color:white; border:none; border-radius:6px; padding:0.2rem 0.5rem; font-size:0.7rem; cursor:pointer;">' +
                    '<i class="bi bi-trash3"></i></button></td>';
                row.children[0].textContent = ing.name;
                row.children[1].textContent = ing.quantity_used + (ing.unit ? ' ' + ing.unit : '');
                tbody.appendChild(row);

                document.getElementById('recipe-empty-' + blockId).style.display = 'none';
                document.getElementById('recipe-table-' + blockId).style.display = '';

                resetEntryRow();
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
        var block = btn.closest('.recipe-block');
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
                }
            });
    });
</script>
@endpush

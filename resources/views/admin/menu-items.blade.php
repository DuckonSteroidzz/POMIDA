@extends('admin.layout')

@section('title', 'Menu Items - Peachy Admin')

@section('content')

<p class="page-title">Menu Items</p>

{{-- Search + Filter --}}
<div class="content-card">
    <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <div class="search-wrapper" style="flex: 1; min-width: 200px;">
            <i class="bi bi-search"></i>
            <input type="text" class="search-input" style="width: 100%;" placeholder="Search..." id="searchInput" onkeyup="searchTable()">
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
        @if(auth()->check() && auth()->user()->role === 'admin')
        @if(isset($selectedBranch) && $selectedBranch === 'all')
        <button type="button"
            class="btn-primary-custom"
            disabled
            title="Select a specific branch first"
            style="padding: 0.55rem 1.25rem; white-space: nowrap; opacity:0.5; cursor:not-allowed;">
            <i class="bi bi-plus-circle"></i> Add New Item
        </button>
        @else
        @php $adminBranch = session('selected_branch_id', 'all'); @endphp
        @if($adminBranch === 'all')
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
        @endif
    </div>
</div>

@if(isset($selectedBranch) && $selectedBranch === 'all')
<div style="background:#fff3cd;color:#856404;padding:0.75rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:0.82rem;font-weight:600;">
    <i class="bi bi-info-circle"></i>
    All Branches is for viewing only. Select a specific branch before adding inventory-linked menu items.
</div>
@endif

{{-- Table --}}
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
                <th>Branch</th>
                <th>Available</th>
                @if(auth()->check() && auth()->user()->role === 'admin')
                <th>Edit</th>
                <th>Delete</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @if(isset($menuItems) && count($menuItems) > 0)
            @foreach($menuItems as $item)
            <tr>
                <td>
                    @if($item->image)
                    <img src="{{ asset($item->image) }}" style="width:45px; height:45px; border-radius:6px; object-fit:cover;">
                    @else
                    <div style="width:45px; height:45px; border-radius:6px; background:#f0f0f0; display:flex; align-items:center; justify-content:center;">
                        <i class="bi bi-image" style="color:#ccc;"></i>
                    </div>
                    @endif
                </td>
                <td style="font-weight: 500;">{{ $item->name }}</td>
                <td style="max-width: 200px; color: #888; font-size: 0.72rem;">{{ $item->description ?? '-' }}</td>
                <td>{{ $item->category->name ?? '-' }}</td>
                <td>{{ $item->subcategory->name ?? '-' }}</td>
                <td style="font-weight: 600;">₱{{ number_format($item->price, 2) }}</td>
                <td>
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
                <td>
                    <form action="{{ route('admin.menu-items.toggle', $item->id) }}" method="POST" style="margin: 0;">
                        @csrf @method('PUT')
                        <input type="checkbox" onchange="this.form.submit()" {{ $item->is_available ? 'checked' : '' }} style="width:16px; height:16px; accent-color:#F4845F; cursor:pointer;">
                    </form>
                </td>
                @if(auth()->check() && auth()->user()->role === 'admin')
                <td>
                    <button type="button" class="btn-edit-custom"
                        data-id="{{ $item->id }}"
                        data-name="{{ $item->name }}"
                        data-description="{{ $item->description }}"
                        data-category="{{ $item->category_id }}"
                        data-subcategory="{{ $item->subcategory_id }}"
                        data-price="{{ $item->price }}"
                        data-cost="{{ $item->cost }}"
                        data-inventory="{{ $item->inventory_item_id }}"
                        data-amount-used="{{ $item->inventory_amount_used }}"
                        data-image="{{ $item->image ? asset($item->image) : '' }}"
                        data-branch="{{ $item->branch_id ?? '' }}"
                        onclick="openEditModal(this)">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                </td>
                <td>
                    <button class="btn-danger-custom" data-id="{{ $item->id }}" onclick="confirmDelete(this.dataset.id)">
                        <i class="bi bi-trash3"></i>
                    </button>
                </td>
                @endif
            </tr>
            @endforeach
            @else
            <tr>
                <td colspan="9" style="text-align: center; color: #aaa; padding: 2rem;">No menu items yet. Click "Add New Item" to get started!</td>
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
                    <input type="text" name="name" id="itemName" class="form-control-custom" placeholder="Enter item name" required style="margin-bottom:0;">
                </div>
            </div>

            <div style="display: flex; gap: 0.75rem; margin-bottom: 0.85rem;">
                <div style="flex: 1;">
                    <label class="form-label-custom">Main Category</label>
                    <select name="category_id" id="itemCategory" class="form-control-custom" required style="margin-bottom:0;">
                        <option value=""> Select </option>
                        @if(isset($categories))
                        @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
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
                        <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                        @endforeach
                        @endif
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 0.85rem;">
                <label class="form-label-custom" style="background-color: #C0392B; color: white; padding: 0.4rem 0.85rem; border-radius: 6px 6px 0 0; display: block; margin-bottom: 0;">
                    Item Description
                </label>
                <textarea name="description" id="itemDescription" rows="3" class="form-control-custom"
                    style="border-radius: 0 0 8px 8px; resize: none; margin-bottom: 0;"
                    placeholder="Enter a short description"></textarea>
            </div>

            <div style="display: flex; gap: 0.75rem; margin-bottom: 0.85rem;">
                <div style="flex: 1;">
                    <label class="form-label-custom">Price (₱)</label>
                    <input type="number" name="price" id="itemPrice" class="form-control-custom" placeholder="0.00" step="0.01" min="0" required style="margin-bottom:0;">
                </div>
                <div style="flex: 1;">
                    <label class="form-label-custom">Cost</label>
                    <input type="number" name="cost" id="itemCost" class="form-control-custom" placeholder="0.00" step="0.01" min="0" style="margin-bottom:0;">
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
                <small style="font-size:0.7rem;color:#888;">Item will be assigned to the currently selected branch.</small>
            </div>

            <div style="display: flex; gap: 0.75rem; margin-bottom: 0.85rem;">
                <div style="flex: 1;">
                    <label class="form-label-custom">Ingredients</label>
                    <select name="inventory_item_id" id="itemInventory" class="form-control-custom" style="margin-bottom:0;">
                        <option value="">-- No ingredient link --</option>
                        @if(isset($inventoryItems) && count($inventoryItems) > 0)
                        @foreach($inventoryItems as $inv)
                        <option value="{{ $inv->id }}">{{ $inv->item_name }} ({{ $inv->quantity }} {{ $inv->unit }})</option>
                        @endforeach
                        @else
                        <option value="" disabled>No inventory for this branch yet</option>
                        @endif
                    </select>
                    <small style="font-size:0.7rem;color:#888;">
                        <i class="bi bi-info-circle"></i> Inventory deduction will not happen unless an ingredient is linked.
                    </small>
                </div>
                <div style="flex: 1;">
                    <label class="form-label-custom">Amount Used per Order</label>
                    <input type="number" name="inventory_amount_used" id="itemAmountUsed" class="form-control-custom" placeholder="e.g. 1" step="0.01" min="0" value="0" style="margin-bottom:0;">

                </div>
            </div>

            <div id="currentImageWrapper" style="display:none; margin-bottom: 0.85rem;">
                <label class="form-label-custom">Current Image</label>
                <div>
                    <img id="currentImage" src="" style="width: 80px; height: 80px; border-radius: 8px; object-fit: cover; border: 1px solid #ddd;">
                </div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label class="form-label-custom" id="imageLabel">Item Image </label>
                <input type="file" name="image" class="form-control-custom" accept="image/*" style="margin-bottom:0; padding: 0.45rem 0.85rem;">
                <small style="color: #888; font-size: 0.7rem;">JPG, PNG, or WEBP. Max 2MB.</small>
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
    // Open Add Modal
    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'New Menu Item';
        document.getElementById('submitBtn').innerText = 'Add Item';
        document.getElementById('itemForm').action = `{{ route('admin.new-menu-item.post') }}`;
        document.getElementById('formMethod').value = 'POST';
        document.getElementById('itemName').value = '';
        document.getElementById('itemCategory').value = '';
        document.getElementById('itemSubcategory').value = '';
        document.getElementById('itemDescription').value = '';
        document.getElementById('itemPrice').value = '';
        document.getElementById('itemCost').value = '';
        document.getElementById('itemInventory').value = '';
        document.getElementById('itemAmountUsed').value = '0';
        document.getElementById('itemBranch').value = '';
        document.getElementById('currentImageWrapper').style.display = 'none';
        document.getElementById('imageLabel').innerText = 'Item Image (Optional)';
        document.getElementById('itemModal').style.display = 'flex';
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
        document.getElementById('itemModal').style.display = 'flex';
    }

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

    // Auto-show modal if errors (sticky form)
    var hasErrors = "{{ $errors->any() ? '1' : '0' }}";
    if (hasErrors === '1') {
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('itemModal').style.display = 'flex';
        });
    }
</script>
@endpush
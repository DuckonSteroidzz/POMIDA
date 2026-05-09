@extends('admin.layout')

@section('title', 'Inventory - Peachy Admin')

@section('content')

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem;">
    <p class="page-title" style="margin-bottom:0;">Inventory</p>
    <span style="font-size:0.88rem; font-weight:500; color:#555;">
        Total Items: <strong>{{ isset($inventory) ? count($inventory) : 0 }}</strong>
    </span>
</div>

{{-- Search + Add --}}
<div class="content-card">
    <div style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
        <div class="search-wrapper" style="flex:1; min-width:200px;">
            <i class="bi bi-search"></i>
            <input type="text" class="search-input" style="width:100%;" placeholder="Search items..." id="searchInput" onkeyup="searchTable()">
        </div>
        @if(auth()->check() && auth()->user()->role === 'admin')
        <button class="btn-primary-custom" onclick="openAddModal()" style="padding:0.55rem 1.25rem; white-space:nowrap;">
            <i class="bi bi-plus-circle"></i> Add Item
        </button>
        @endif
    </div>
</div>

{{-- Errors --}}
@if($errors->any())
<div style="background:#f8d7da; color:#721c24; padding:0.6rem 0.85rem; border-radius:8px; font-size:0.82rem; margin-bottom:1rem;">
    @foreach($errors->all() as $error)
        <div>{{ $error }}</div>
    @endforeach
</div>
@endif

{{-- Inventory Table --}}
<div class="content-card" style="overflow-x:auto;">
    <table class="table-custom" id="inventoryTable">
        <thead>
            <tr>
                <th>No.</th>
                <th>Item Name</th>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Low Stock Alert</th>
                <th>Status</th>
                <th>Stock In</th>
                <th>Stock Out</th>
                @if(auth()->check() && auth()->user()->role === 'admin')
                <th>Edit</th>
                <th>Delete</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @if(isset($inventory) && count($inventory) > 0)
                @foreach($inventory as $index => $item)
                @php
                    $isLow = $item->quantity <= $item->low_stock_alert;
                    $isOut = $item->quantity == 0;
                @endphp
                <tr>
                    <td style="color:#888;">{{ $index + 1 }}</td>
                    <td style="font-weight:500;">{{ $item->item_name }}</td>
                    <td style="font-weight:600; color:{{ $isOut ? '#C0392B' : ($isLow ? '#F4845F' : '#333') }};">
                        {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}
                    </td>
                    <td style="color:#888;">{{ $item->unit }}</td>
                    <td style="color:#888; font-size:0.75rem;">{{ rtrim(rtrim(number_format($item->low_stock_alert, 2), '0'), '.') }}</td>
                    <td>
                        @if($isOut)
                            <span style="background:#C0392B; color:white; padding:0.2rem 0.6rem; border-radius:4px; font-size:0.7rem; font-weight:600;">Out of Stock</span>
                        @elseif($isLow)
                            <span style="background:#F4845F; color:white; padding:0.2rem 0.6rem; border-radius:4px; font-size:0.7rem; font-weight:600;">Low Stock</span>
                        @else
                            <span style="background:#4CAF50; color:white; padding:0.2rem 0.6rem; border-radius:4px; font-size:0.7rem; font-weight:600;">In Stock</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn-primary-custom" style="padding:0.3rem 0.6rem; font-size:0.7rem;"
                            data-id="{{ $item->id }}" data-name="{{ $item->item_name }}" data-unit="{{ $item->unit }}"
                            onclick="openStockModal(this, 'in')">
                            <i class="bi bi-plus"></i> In
                        </button>
                    </td>
                    <td>
                        <button class="btn-danger-custom" style="padding:0.3rem 0.6rem; font-size:0.7rem;"
                            data-id="{{ $item->id }}" data-name="{{ $item->item_name }}" data-unit="{{ $item->unit }}"
                            onclick="openStockModal(this, 'out')">
                            <i class="bi bi-dash"></i> Out
                        </button>
                    </td>
                    @if(auth()->check() && auth()->user()->role === 'admin')
                    <td>
                        <button class="btn-edit-custom"
                            data-id="{{ $item->id }}"
                            data-name="{{ $item->item_name }}"
                            data-code="{{ $item->item_code }}"
                            data-category="{{ $item->category }}"
                            data-quantity="{{ $item->quantity }}"
                            data-unit="{{ $item->unit }}"
                            data-low="{{ $item->low_stock_alert }}"
                            data-cost="{{ $item->unit_cost }}"
                            data-supplier="{{ $item->supplier }}"
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
                    <td colspan="10" style="text-align:center; color:#aaa; padding:2rem;">No inventory items yet. Click "Add Item" to start tracking stock!</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

{{-- Stock Movements Log --}}
<div class="content-card">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
        <h3 style="font-size:1rem; font-weight:700; color:#333; margin:0;">
            <i class="bi bi-clock-history"></i> Stock Movements Log
        </h3>
        <span style="font-size:0.75rem; color:#888;">Last 20 movements</span>
    </div>

    <div style="overflow-x:auto;">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Stock After</th>
                    <th>Reason</th>
                    <th>Source</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                @if(isset($stockMovements) && count($stockMovements) > 0)
                    @foreach($stockMovements as $mv)
                    <tr>
                        <td style="font-size:0.7rem; color:#888;">{{ $mv->created_at->format('M d, Y h:i A') }}</td>
                        <td style="font-weight:500;">{{ $mv->inventory->item_name ?? 'N/A' }}</td>
                        <td>
                            @if($mv->movement_type === 'in')
                                <span style="background:#4CAF50; color:white; padding:0.2rem 0.5rem; border-radius:4px; font-size:0.7rem; font-weight:600;">
                                    <i class="bi bi-arrow-down-circle"></i> IN
                                </span>
                            @else
                                <span style="background:#C0392B; color:white; padding:0.2rem 0.5rem; border-radius:4px; font-size:0.7rem; font-weight:600;">
                                    <i class="bi bi-arrow-up-circle"></i> OUT
                                </span>
                            @endif
                        </td>
                        <td style="font-weight:600; color:{{ $mv->movement_type === 'in' ? '#4CAF50' : '#C0392B' }};">
                            {{ $mv->movement_type === 'in' ? '+' : '-' }}{{ rtrim(rtrim(number_format($mv->amount, 3), '0'), '.') }}
                            <span style="font-weight:400; color:#888; font-size:0.7rem;">{{ $mv->inventory->unit ?? '' }}</span>
                        </td>
                        <td style="font-weight:500;">{{ rtrim(rtrim(number_format($mv->quantity_after, 2), '0'), '.') }} <span style="font-size:0.7rem; color:#888;">{{ $mv->inventory->unit ?? '' }}</span></td>
                        <td style="font-size:0.75rem; color:#555;">{{ $mv->reason ?? '-' }}</td>
                        <td>
                            @if($mv->source === 'manual')
                                <span style="background:#888; color:white; padding:0.15rem 0.4rem; border-radius:4px; font-size:0.65rem;">Manual</span>
                            @elseif($mv->source === 'order')
                                <span style="background:#F4845F; color:white; padding:0.15rem 0.4rem; border-radius:4px; font-size:0.65rem;">Order</span>
                            @elseif($mv->source === 'spoilage')
                                <span style="background:#C0392B; color:white; padding:0.15rem 0.4rem; border-radius:4px; font-size:0.65rem;">Spoilage</span>
                            @else
                                <span style="background:#8e44ad; color:white; padding:0.15rem 0.4rem; border-radius:4px; font-size:0.65rem;">{{ ucfirst($mv->source) }}</span>
                            @endif
                        </td>
                        <td style="font-size:0.7rem; color:#888;">{{ $mv->user->name ?? 'System' }}</td>
                    </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="8" style="text-align:center; color:#aaa; padding:2rem;">No stock movements yet. Movements will appear here when you Stock In/Out.</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>

{{-- ADD/EDIT MODAL --}}
<div id="itemModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center; padding:1rem;">
    <div style="background:white; border-radius:12px; padding:1.5rem; max-width:550px; width:100%; max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 id="modalTitle" style="font-size:1.1rem; font-weight:700; color:#333; margin:0;">Add Inventory Item</h3>
            <button onclick="closeItemModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#888;">&times;</button>
        </div>

        <form id="itemForm" action="{{ route('admin.inventory.store') }}" method="POST">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div>
                    <label class="form-label-custom">Item Name <span style="color:#C0392B;">*</span></label>
                    <input type="text" name="item_name" id="itemName" class="form-control-custom" placeholder="Enter Name" required>
                </div>
                <div>
                    <label class="form-label-custom">Item Code</label>
                    <input type="text" name="item_code" id="itemCode" class="form-control-custom" placeholder="Enter Code">
                </div>
            </div>

        

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.75rem;">
                <div>
                    <label class="form-label-custom">Quantity <span style="color:#C0392B;">*</span></label>
                    <input type="number" name="quantity" id="itemQuantity" class="form-control-custom" placeholder="0" step="0.01" min="0" required>
                </div>
                <div>
                    <label class="form-label-custom">Unit <span style="color:#C0392B;">*</span></label>
                    <input type="text" name="unit" id="itemUnit" class="form-control-custom" placeholder="kg, pcs, L" required>
                </div>
                <div>
                    <label class="form-label-custom">Low Stock Alert</label>
                    <input type="number" name="low_stock_alert" id="itemLow" class="form-control-custom" placeholder="10" step="0.01" min="0">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div>
                    <label class="form-label-custom">Unit Cost (₱)</label>
                    <input type="number" name="unit_cost" id="itemCost" class="form-control-custom" placeholder="0.00" step="0.01" min="0">
                </div>
                <div>
                    <label class="form-label-custom">Supplier</label>
                    <input type="text" name="supplier" id="itemSupplier" class="form-control-custom" placeholder="Supplier name">
                </div>
            </div>

            <div style="display:flex; gap:0.5rem; margin-top:1rem;">
                <button type="submit" class="btn-primary-custom" id="submitBtn" style="flex:1; padding:0.65rem;">Add Item</button>
                <button type="button" onclick="closeItemModal()" class="btn-danger-custom" style="padding:0.65rem 1.5rem;">Cancel</button>
            </div>
        </form>
    </div>
</div>

{{-- STOCK IN/OUT MODAL --}}
<div id="stockModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; padding:1.5rem; max-width:400px; width:90%;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 id="stockModalTitle" style="font-size:1.05rem; font-weight:700; color:#333; margin:0;">Stock In</h3>
            <button onclick="closeStockModal()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#888;">&times;</button>
        </div>

        <p id="stockItemName" style="font-size:0.85rem; color:#555; margin-bottom:1rem;"></p>

        <form id="stockForm" method="POST">
            @csrf
            <label class="form-label-custom">Amount <span id="stockUnit"></span></label>
            <input type="number" name="amount" id="stockAmount" class="form-control-custom" placeholder="0.00" step="0.01" min="0.01" required autofocus>

            <label class="form-label-custom">Note (Optional)</label>
            <input type="text" name="note" class="form-control-custom" placeholder="e.g., New delivery, Used for pizza">

            <div style="display:flex; gap:0.5rem; margin-top:1rem;">
                <button type="submit" id="stockSubmit" class="btn-primary-custom" style="flex:1; padding:0.65rem;">Confirm</button>
                <button type="button" onclick="closeStockModal()" class="btn-danger-custom" style="padding:0.65rem 1.5rem;">Cancel</button>
            </div>
        </form>
    </div>
</div>

{{-- DELETE MODAL --}}
<div id="deleteModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; padding:2rem; max-width:320px; width:90%; text-align:center;">
        <p style="font-size:0.88rem; font-weight:600; color:#333; margin-bottom:1.5rem;">Delete this inventory item?</p>
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
    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'Add Inventory Item';
        document.getElementById('submitBtn').innerText = 'Add Item';
        document.getElementById('itemForm').action = `{{ route('admin.inventory.store') }}`;
        document.getElementById('formMethod').value = 'POST';
        document.getElementById('itemName').value = '';
        document.getElementById('itemCode').value = '';
        document.getElementById('itemQuantity').value = '';
        document.getElementById('itemUnit').value = '';
        document.getElementById('itemLow').value = '10';
        document.getElementById('itemCost').value = '';
        document.getElementById('itemSupplier').value = '';
        document.getElementById('itemModal').style.display = 'flex';
    }

    function openEditModal(btn) {
        document.getElementById('modalTitle').innerText = 'Edit Inventory Item';
        document.getElementById('submitBtn').innerText = 'Update';
        document.getElementById('itemForm').action = `/admin/inventory/${btn.dataset.id}`;
        document.getElementById('formMethod').value = 'PUT';
        document.getElementById('itemName').value = btn.dataset.name || '';
        document.getElementById('itemCode').value = btn.dataset.code || '';
        document.getElementById('itemQuantity').value = btn.dataset.quantity || '';
        document.getElementById('itemUnit').value = btn.dataset.unit || '';
        document.getElementById('itemLow').value = btn.dataset.low || '';
        document.getElementById('itemCost').value = btn.dataset.cost || '';
        document.getElementById('itemSupplier').value = btn.dataset.supplier || '';
        document.getElementById('itemModal').style.display = 'flex';
    }

    function closeItemModal() {
        document.getElementById('itemModal').style.display = 'none';
    }

    function openStockModal(btn, type) {
        const id = btn.dataset.id;
        const name = btn.dataset.name;
        const unit = btn.dataset.unit;
        if (type === 'in') {
            document.getElementById('stockModalTitle').innerText = 'Stock In (Add Stock)';
            document.getElementById('stockForm').action = `/admin/inventory/stock-in/${id}`;
            document.getElementById('stockSubmit').innerText = 'Add Stock';
        } else {
            document.getElementById('stockModalTitle').innerText = 'Stock Out (Remove Stock)';
            document.getElementById('stockForm').action = `/admin/inventory/stock-out/${id}`;
            document.getElementById('stockSubmit').innerText = 'Remove Stock';
        }
        document.getElementById('stockItemName').innerText = `Item: ${name}`;
        document.getElementById('stockUnit').innerText = `(${unit})`;
        document.getElementById('stockAmount').value = '';
        document.getElementById('stockModal').style.display = 'flex';
    }

    function closeStockModal() {
        document.getElementById('stockModal').style.display = 'none';
    }

    function confirmDelete(id) {
        document.getElementById('deleteForm').action = `/admin/inventory/${id}`;
        document.getElementById('deleteModal').style.display = 'flex';
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
    }

    function searchTable() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        document.querySelectorAll('#inventoryTable tbody tr').forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
        });
    }

    var hasErrors = "{{ $errors->any() ? '1' : '0' }}";
    if (hasErrors === '1') {
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('itemModal').style.display = 'flex';
        });
    }
</script>
@endpush
@extends('admin.layout')

@section('title', 'Inventory - Peachy Admin')

@section('content')

@php
    $adminUser = Auth::guard('admin')->user();
    $isAdmin = $adminUser && $adminUser->role === 'admin';
    $invItems = isset($inventory) ? $inventory : collect();
    $countTotal = count($invItems);
    $countOut = 0; $countLow = 0; $countOk = 0;
    /*
     * The whole branch's stock value — quantity × unit_cost, summed across
     * every item in $invItems, which is already scoped to the selected
     * branch by showInventory(). This is deliberately computed from the
     * SAME unfiltered collection the other KPI tiles above (countTotal,
     * countOk, countLow, countOut) are computed from, not from whatever
     * happens to be visible in the table — the search box and status filter
     * are client-side JS that only hide/show already-rendered rows, and
     * never touch these totals. "What is my inventory worth" is a business
     * figure for the whole branch; it must not silently change just because
     * someone typed into the search box. Computed here, not stored: no new
     * column, no migration, so it always follows the live quantity and
     * unit_cost.
     */
    $totalStockValue = 0;
    foreach ($invItems as $it) {
        if ($it->quantity <= 0) { $countOut++; }
        elseif ($it->quantity <= $it->low_stock_alert) { $countLow++; }
        else { $countOk++; }
        $totalStockValue += (float) $it->quantity * (float) $it->unit_cost;
    }
@endphp

{{-- ── Header ── --}}
<div class="iv-header">
    <div class="iv-header-text">
        <p class="iv-title">Inventory</p>
        <p class="iv-sub">Track stock levels, movements &amp; low-stock alerts</p>
    </div>
    <div class="iv-actions">
        <span class="iv-chip">Total Items: {{ $countTotal }}</span>
        @if($isAdmin)
        <button type="button" class="iv-btn iv-btn-solid" onclick="openAddModal()">
            <i class="bi bi-plus-circle"></i> Add Item
        </button>
        @endif
    </div>
</div>

{{-- ── Stat strip ── --}}
<div class="iv-stats">
    <button type="button" class="iv-stat" data-status="" onclick="ivSetStatus('')">
        <span class="iv-stat-num">{{ $countTotal }}</span>
        <span class="iv-stat-label">All Items</span>
    </button>
    <button type="button" class="iv-stat iv-stat-ok" data-status="in" onclick="ivSetStatus('in')">
        <span class="iv-stat-num">{{ $countOk }}</span>
        <span class="iv-stat-label">In Stock</span>
    </button>
    <button type="button" class="iv-stat iv-stat-low" data-status="low" onclick="ivSetStatus('low')">
        <span class="iv-stat-num">{{ $countLow }}</span>
        <span class="iv-stat-label">Low Stock</span>
    </button>
    <button type="button" class="iv-stat iv-stat-out" data-status="out" onclick="ivSetStatus('out')">
        <span class="iv-stat-num">{{ $countOut }}</span>
        <span class="iv-stat-label">Out of Stock</span>
    </button>
    {{--
        A <div>, not a <button> like the four tiles above: those are status
        filters (clicking one calls ivSetStatus()), and this tile is not a
        filter — there is no "value" status to switch the table to. Same
        visual classes as the others (iv-stat / iv-stat-num / iv-stat-label)
        so it reads as one family of tiles; cursor:default only, so it does
        not invite a click that would do nothing.
    --}}
    <div class="iv-stat" style="cursor:default;">
        <span class="iv-stat-num">₱{{ number_format($totalStockValue, 2) }}</span>
        <span class="iv-stat-label">Total Stock Value</span>
    </div>
</div>

{{-- ── Errors ── --}}
@if($errors->any())
<div class="iv-alert">
    @foreach($errors->all() as $error)
        <div><i class="bi bi-exclamation-triangle-fill"></i> {{ $error }}</div>
    @endforeach
</div>
@endif

{{-- ── Filters ── --}}
<div class="iv-card iv-filter-card">
    <div class="iv-filter">
        <div class="iv-field iv-field-grow">
            <label class="iv-label" for="searchInput">Search</label>
            <div class="iv-search">
                <i class="bi bi-search"></i>
                <input type="text" class="iv-input" id="searchInput" aria-label="Search inventory" onkeyup="searchTable()" autocomplete="off">
            </div>
        </div>
        <div class="iv-field">
            <label class="iv-label" for="ivStatus">Status</label>
            <select id="ivStatus" class="iv-input" onchange="ivSetStatus(this.value)">
                <option value="">All Statuses</option>
                <option value="in">In Stock</option>
                <option value="low">Low Stock</option>
                <option value="out">Out of Stock</option>
            </select>
        </div>
        <div class="iv-field iv-field-actions">
            <button type="button" class="iv-btn iv-btn-ghost" onclick="ivReset()">
                <i class="bi bi-arrow-counterclockwise"></i> Reset
            </button>
        </div>
    </div>
</div>

{{-- ── Inventory Table ── --}}
<div class="iv-card iv-table-card">
    <table class="iv-table" id="inventoryTable">
        <thead>
            <tr>
                <th class="iv-ta-left">No.</th>
                <th class="iv-ta-left">Item Name</th>
                <th class="iv-ta-right">Quantity</th>
                <th class="iv-ta-left">Unit</th>
                <th class="iv-ta-right">Low Stock Alert</th>
                <th class="iv-ta-right">Stock Value</th>
                <th class="iv-ta-center">Status</th>
                <th class="iv-ta-center">Stock In</th>
                <th class="iv-ta-center">Stock Out</th>
                @if($isAdmin)
                <th class="iv-ta-center">Edit</th>
                <th class="iv-ta-center">Delete</th>
                @endif
            </tr>
        </thead>
        <tbody id="ivBody">
            @if($countTotal > 0)
                @foreach($invItems as $index => $item)
                @php
                    $isOut = $item->quantity <= 0;
                    $isLow = $item->quantity > 0 && $item->quantity <= $item->low_stock_alert;
                    $statusKey = $isOut ? 'out' : ($isLow ? 'low' : 'in');
                @endphp
                <tr class="iv-row" data-status="{{ $statusKey }}">
                    <td data-label="No." class="iv-muted">{{ $index + 1 }}</td>
                    <td data-label="Item" class="iv-name">{{ $item->item_name }}</td>
                    <td data-label="Quantity" class="iv-ta-right iv-num iv-qty-{{ $statusKey }}">
                        {{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}
                    </td>
                    <td data-label="Unit" class="iv-muted">{{ $item->unit }}</td>
                    <td data-label="Low Alert" class="iv-ta-right iv-num iv-muted">{{ rtrim(rtrim(number_format($item->low_stock_alert, 2), '0'), '.') }}</td>
                    {{--
                        quantity × unit_cost for THIS row. unit_cost is a
                        NOT NULL decimal column (defaults to 0.00), so this
                        can legitimately be zero but is never null — no extra
                        guard needed to avoid a blank cell or a crash.
                    --}}
                    <td data-label="Stock Value" class="iv-ta-right iv-num">₱{{ number_format($item->quantity * $item->unit_cost, 2) }}</td>
                    <td data-label="Status" class="iv-ta-center">
                        @if($isOut)
                            <span class="iv-badge iv-badge-out">Out of Stock</span>
                        @elseif($isLow)
                            <span class="iv-badge iv-badge-low">Low Stock</span>
                        @else
                            <span class="iv-badge iv-badge-ok">In Stock</span>
                        @endif
                    </td>
                    <td data-label="Stock In" class="iv-ta-center">
                        <button class="iv-mini iv-mini-in"
                            data-id="{{ $item->id }}" data-name="{{ $item->item_name }}" data-unit="{{ $item->unit }}"
                            onclick="openStockModal(this, 'in')">
                            <i class="bi bi-plus"></i> In
                        </button>
                    </td>
                    <td data-label="Stock Out" class="iv-ta-center">
                        <button class="iv-mini iv-mini-out"
                            data-id="{{ $item->id }}" data-name="{{ $item->item_name }}" data-unit="{{ $item->unit }}"
                            onclick="openStockModal(this, 'out')">
                            <i class="bi bi-dash"></i> Out
                        </button>
                    </td>
                    @if($isAdmin)
                    <td data-label="Edit" class="iv-ta-center">
                        <button class="iv-icon iv-icon-edit"
                            data-id="{{ $item->id }}"
                            data-name="{{ $item->item_name }}"
                            data-code="{{ $item->item_code }}"
                            data-category="{{ $item->category }}"
                            data-quantity="{{ $item->quantity }}"
                            data-unit="{{ $item->unit }}"
                            data-low="{{ $item->low_stock_alert }}"
                            data-cost="{{ $item->unit_cost }}"
                            data-supplier="{{ $item->supplier }}"
                            onclick="openEditModal(this)" title="Edit">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                    </td>
                    <td data-label="Delete" class="iv-ta-center">
                        <button class="iv-icon iv-icon-del" data-id="{{ $item->id }}" onclick="confirmDelete(this.dataset.id)" title="Delete">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                    @endif
                </tr>
                @endforeach
            @else
                <tr class="iv-empty-row">
                    <td colspan="11">
                        <div class="iv-empty">
                            <i class="bi bi-box-seam"></i>
                            <p>No inventory items yet</p>
                            <span>Click &ldquo;Add Item&rdquo; to start tracking stock.</span>
                        </div>
                    </td>
                </tr>
            @endif
            <tr class="iv-nores-row" id="ivNoResults" hidden>
                <td colspan="11">
                    <div class="iv-empty">
                        <i class="bi bi-search"></i>
                        <p>No matching items</p>
                        <span>Try a different search term or status filter.</span>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>

    {{-- ── Pagination ── --}}
    <div class="iv-pager" id="ivPager" hidden>
        <div class="iv-pager-info">
            <span id="ivPagerRange"></span>
            <label class="iv-pager-size">
                Rows
                <select id="ivPageSize" class="iv-input iv-input-sm">
                    <option value="10">10</option>
                    <option value="15" selected>15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </label>
        </div>
        <div class="iv-pager-nav">
            <button type="button" class="iv-page-btn" id="ivPrev" onclick="ivGo(ivPage - 1)">
                <i class="bi bi-chevron-left"></i>
            </button>
            <span class="iv-page-list" id="ivPageList"></span>
            <button type="button" class="iv-page-btn" id="ivNext" onclick="ivGo(ivPage + 1)">
                <i class="bi bi-chevron-right"></i>
            </button>
        </div>
    </div>
</div>

{{-- ── Stock Movements Log ── --}}
<div class="iv-card iv-table-card">
    <div class="iv-card-head">
        <h3 class="iv-card-title"><i class="bi bi-clock-history"></i> Stock Movements Log</h3>
        <span class="iv-chip iv-chip-soft">Last 20 movements</span>
    </div>

    {{-- Why there is no Delete button on this log.

         Each row is one half of the explanation for the current stock number.
         Removing a movement would leave a count nobody can account for, which
         is the opposite of what an audit log is for. Corrections are made by
         recording the opposite movement, so the trail shows what happened AND
         what was done about it. --}}
    <div style="border-left:5px solid #F4845F;background:#fffaf6;padding:0.8rem 1rem;margin-bottom:0.9rem;font-size:0.8rem;line-height:1.6;color:#5d4a44;border-radius:10px;">
        <i class="bi bi-shield-check" style="color:#C0392B;"></i>
        <strong style="color:#8B1A1A;">This log is kept on purpose.</strong>
        These rows are what explains the current stock count, so there is no delete button here by
        design. If a movement was recorded wrongly, correct it with a matching Stock In or Stock Out
        and a reason — the log then shows both what happened and how it was fixed.
    </div>

    <table class="iv-table">
        <thead>
            <tr>
                <th class="iv-ta-left">Date &amp; Time</th>
                <th class="iv-ta-left">Item</th>
                <th class="iv-ta-center">Type</th>
                <th class="iv-ta-right">Amount</th>
                <th class="iv-ta-right">Stock After</th>
                <th class="iv-ta-left">Reason</th>
                <th class="iv-ta-center">Source</th>
                <th class="iv-ta-left">By</th>
            </tr>
        </thead>
        <tbody>
            @if(isset($stockMovements) && count($stockMovements) > 0)
                @foreach($stockMovements as $mv)
                <tr class="iv-row">
                    <td data-label="Date" class="iv-date">{{ $mv->created_at->format('M d, Y h:i A') }}</td>
                    <td data-label="Item" class="iv-name">{{ $mv->inventory->item_name ?? 'N/A' }}</td>
                    <td data-label="Type" class="iv-ta-center">
                        @if($mv->movement_type === 'in')
                            <span class="iv-badge iv-badge-ok"><i class="bi bi-arrow-down-circle"></i> IN</span>
                        @else
                            <span class="iv-badge iv-badge-out"><i class="bi bi-arrow-up-circle"></i> OUT</span>
                        @endif
                    </td>
                    <td data-label="Amount" class="iv-ta-right iv-num {{ $mv->movement_type === 'in' ? 'iv-amt-in' : 'iv-amt-out' }}">
                        {{ $mv->movement_type === 'in' ? '+' : '-' }}{{ rtrim(rtrim(number_format($mv->amount, 3), '0'), '.') }}
                        <span class="iv-unit">{{ $mv->inventory->unit ?? '' }}</span>
                    </td>
                    <td data-label="Stock After" class="iv-ta-right iv-num">
                        {{ rtrim(rtrim(number_format($mv->quantity_after, 2), '0'), '.') }}
                        <span class="iv-unit">{{ $mv->inventory->unit ?? '' }}</span>
                    </td>
                    <td data-label="Reason" class="iv-reason">{{ $mv->reason ?? '-' }}</td>
                    <td data-label="Source" class="iv-ta-center">
                        @if($mv->source === 'manual')
                            <span class="iv-tag iv-tag-manual">Manual</span>
                        @elseif($mv->source === 'order')
                            <span class="iv-tag iv-tag-order">Order</span>
                        @elseif($mv->source === 'spoilage')
                            <span class="iv-tag iv-tag-spoil">Spoilage</span>
                        @else
                            <span class="iv-tag iv-tag-other">{{ ucfirst($mv->source) }}</span>
                        @endif
                    </td>
                    <td data-label="By" class="iv-muted">{{ $mv->user->name ?? 'System' }}</td>
                </tr>
                @endforeach
            @else
                <tr class="iv-empty-row">
                    <td colspan="8">
                        <div class="iv-empty">
                            <i class="bi bi-arrow-left-right"></i>
                            <p>No stock movements yet</p>
                            <span>Movements appear here when you Stock In/Out.</span>
                        </div>
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

{{-- ADD/EDIT MODAL --}}
<div id="itemModal" class="iv-modal">
    <div class="iv-modal-box iv-modal-lg">
        <div class="iv-modal-head">
            <h3 id="modalTitle" class="iv-modal-title">Add Inventory Item</h3>
            <button type="button" class="iv-modal-x" onclick="closeItemModal()">&times;</button>
        </div>

        {{--
            EDIT CONTEXT SURVIVING A VALIDATION ERROR (2026-09-02).

            This form is reused for BOTH Add and Edit, with the action/method/
            title switched entirely by JS (openAddModal()/openEditModal())
            when the admin clicks a button. That JS state does not survive a
            failed submission: $request->validate() failing sends a normal
            redirect back to a fresh GET /admin/inventory, so the modal
            re-renders from scratch. The $errors->any() script further down
            already reopened the modal on error, but with every field BLANK
            (no old() binding existed) and the form still pointed at the
            CREATE route — an admin correcting an edit and pressing Update
            again would have silently created a brand new item instead.

            editing_item_id is the missing piece: a plain form field, so
            Laravel's automatic withInput() flashes it back like any other.
            Its presence in old() is what the script below uses to tell "an
            edit failed" from "a create failed" and to know which item to
            keep pointing at.
        --}}
        <form id="itemForm" action="{{ route('admin.inventory.store') }}" method="POST">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">
            <input type="hidden" name="editing_item_id" id="editingItemId" value="{{ old('editing_item_id') }}">

            <div class="iv-grid-2">
                <div class="iv-field">
                    <label class="iv-label">Item Name <span class="iv-req">*</span></label>
                    <input type="text" name="item_name" id="itemName" class="iv-input" value="{{ old('item_name') }}" required autocomplete="off">
                </div>
                <div class="iv-field">
                    {{-- Marked required to match the column: inventory.item_code
                         is NOT NULL, so a blank submission has always been
                         rejected — the field just never said so. --}}
                    <label class="iv-label">Item Code <span class="iv-req">*</span></label>
                    <input type="text" name="item_code" id="itemCode" class="iv-input" value="{{ old('item_code') }}" required autocomplete="off">
                </div>
            </div>

            <div class="iv-grid-3">
                <div class="iv-field">
                    <label class="iv-label">Quantity <span class="iv-req">*</span></label>
                    <input type="number" name="quantity" id="itemQuantity" class="iv-input" step="0.01" min="0" value="{{ old('quantity') }}" required>
                </div>
                <div class="iv-field">
                    <label class="iv-label">Unit <span class="iv-req">*</span></label>
                    <select name="unit" id="itemUnit" class="iv-input" required>
                        <option value="">-- Select unit --</option>
                        @foreach(\App\Models\Inventory::ALLOWED_UNITS as $u)
                            <option value="{{ $u }}" {{ old('unit') === $u ? 'selected' : '' }}>{{ $u }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="iv-field">
                    <label class="iv-label">Low Stock Alert</label>
                    <input type="number" name="low_stock_alert" id="itemLow" class="iv-input" step="0.01" min="0" value="{{ old('low_stock_alert') }}">
                </div>
            </div>

            <div class="iv-grid-2">
                <div class="iv-field">
                    <label class="iv-label">Unit Cost (₱)</label>
                    <input type="number" name="unit_cost" id="itemCost" class="iv-input" step="0.01" min="0" value="{{ old('unit_cost') }}">
                </div>
                <div class="iv-field">
                    <label class="iv-label">Supplier</label>
                    <input type="text" name="supplier" id="itemSupplier" class="iv-input" value="{{ old('supplier') }}" autocomplete="off">
                </div>
            </div>

            <div class="iv-modal-foot">
                <button type="submit" class="iv-btn iv-btn-solid iv-btn-block" id="submitBtn">Add Item</button>
                <button type="button" class="iv-btn iv-btn-ghost" onclick="closeItemModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

{{-- STOCK IN/OUT MODAL --}}
<div id="stockModal" class="iv-modal">
    <div class="iv-modal-box">
        <div class="iv-modal-head">
            <h3 id="stockModalTitle" class="iv-modal-title">Stock In</h3>
            <button type="button" class="iv-modal-x" onclick="closeStockModal()">&times;</button>
        </div>

        <p id="stockItemName" class="iv-modal-note"></p>

        <form id="stockForm" method="POST">
            @csrf
            <div class="iv-field">
                <label class="iv-label">Amount <span id="stockUnit"></span></label>
                <input type="number" name="amount" id="stockAmount" class="iv-input" step="0.01" min="0.01" required autofocus>
            </div>
            <div class="iv-field">
                <label class="iv-label">Note (Optional)</label>
                <input type="text" name="note" class="iv-input" autocomplete="off">
            </div>

            <div class="iv-modal-foot">
                <button type="submit" id="stockSubmit" class="iv-btn iv-btn-solid iv-btn-block">Confirm</button>
                <button type="button" class="iv-btn iv-btn-ghost" onclick="closeStockModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

{{-- DELETE MODAL --}}
<div id="deleteModal" class="iv-modal">
    <div class="iv-modal-box iv-modal-sm">
        <div class="iv-confirm-ico"><i class="bi bi-trash3"></i></div>
        <p class="iv-confirm-text">Delete this inventory item?</p>
        <div class="iv-confirm-actions">
            <form id="deleteForm" method="POST">
                @csrf @method('DELETE')
                <button type="submit" class="iv-btn iv-btn-solid">Yes, delete</button>
            </form>
            <button type="button" class="iv-btn iv-btn-ghost" onclick="closeDeleteModal()">Cancel</button>
        </div>
    </div>
</div>

@endsection

@push('styles')
<link href="/vendor/gfonts.css" rel="stylesheet">
<style>
    .iv-header, .iv-card, .iv-pager, .iv-stats, .iv-alert, .iv-modal {
        font-family: 'Karla', system-ui, sans-serif;
        color: #3B2A24;
    }

    /* ── Header ── */
    .iv-header { display: grid; grid-template-columns: minmax(0,1fr); gap: .85rem; margin-bottom: 1.1rem; }
    .iv-title { font-family: 'Fraunces', Georgia, serif; font-weight: 700; font-size: 1.75rem; letter-spacing: -.01em; margin: 0; color: #3B2A24; }
    .iv-sub { margin: .15rem 0 0; font-size: .82rem; color: #8B7A72; }
    .iv-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
    .iv-chip { font-size: .72rem; font-weight: 700; color: #C0392B; background: #FDF1E6; border: 1px solid #F8D7B0; border-radius: 999px; padding: .35rem .7rem; }
    .iv-chip-soft { color: #8B7A72; background: #FFFBF7; border-color: #F0E2D5; }

    /* ── Buttons ── */
    .iv-btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
        font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: .78rem; font-weight: 700;
        padding: .55rem 1rem; border-radius: 10px; border: 1px solid transparent;
        cursor: pointer; text-decoration: none; line-height: 1.1;
        transition: transform .12s ease, box-shadow .12s ease, background .12s ease; }
    .iv-btn:hover { transform: translateY(-1px); }
    .iv-btn-solid { background: linear-gradient(135deg, #F4845F, #EF8585); color: #fff; box-shadow: 0 6px 14px -8px rgba(192,57,43,.6); }
    .iv-btn-solid:hover { background: linear-gradient(135deg, #EF8585, #C0392B); color: #fff; }
    .iv-btn-ghost { background: #fff; color: #C0392B; border-color: #F6B49B; }
    .iv-btn-ghost:hover { background: #FDF6EF; color: #C0392B; }
    .iv-btn-block { flex: 1; }

    /* ── Stats ── */
    .iv-stats { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: .6rem; margin-bottom: 1rem; }
    .iv-stat { display: flex; flex-direction: column; align-items: flex-start; gap: .1rem;
        background: #fff; border: 1px solid #F0E2D5; border-left: 4px solid #F6B49B; border-radius: 14px;
        padding: .7rem .85rem; cursor: pointer; text-align: left;
        box-shadow: 0 10px 30px -26px rgba(59,42,36,.5); transition: border-color .12s ease, transform .12s ease; }
    .iv-stat:hover { transform: translateY(-1px); }
    .iv-stat.is-active { border-color: #F4845F; box-shadow: 0 0 0 3px rgba(244,132,95,.15); }
    .iv-stat-num { font-family: 'Fraunces', Georgia, serif; font-size: 1.3rem; font-weight: 700; color: #3B2A24; line-height: 1.1; }
    .iv-stat-label { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #8B7A72; }
    .iv-stat-ok { border-left-color: #2E7D5B; }
    .iv-stat-low { border-left-color: #F4845F; }
    .iv-stat-out { border-left-color: #C0392B; }

    /* ── Alert ── */
    .iv-alert { background: #FBE7E4; border: 1px solid #F6C9C1; border-left: 4px solid #C0392B;
        color: #C0392B; padding: .7rem .9rem; border-radius: 12px; font-size: .8rem; margin-bottom: 1rem; }
    .iv-alert div + div { margin-top: .2rem; }

    /* ── Cards ── */
    .iv-card { background: #fff; border: 1px solid #F0E2D5; border-radius: 16px;
        box-shadow: 0 10px 30px -24px rgba(59,42,36,.45); margin-bottom: 1rem; }
    .iv-filter-card { padding: 1rem 1.1rem; background: linear-gradient(180deg, #FDF6EF, #fff); }
    .iv-table-card { padding: 0; overflow-x: auto; }
    .iv-card-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem;
        flex-wrap: wrap; padding: .95rem 1.1rem; border-bottom: 1px solid #F0E2D5; }
    .iv-card-title { font-family: 'Fraunces', Georgia, serif; font-size: 1rem; font-weight: 600; color: #3B2A24; margin: 0; }
    .iv-card-title i { color: #F4845F; }

    /* ── Filters / fields ── */
    .iv-filter { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .75rem; align-items: end; }
    .iv-field { display: flex; flex-direction: column; gap: .3rem; min-width: 0; }
    .iv-field + .iv-field, .iv-grid-2 + .iv-grid-2, .iv-grid-2 + .iv-grid-3, .iv-grid-3 + .iv-grid-2 { margin-top: 0; }
    .iv-field-grow { grid-column: span 2; }
    .iv-field-actions { flex-direction: row; gap: .5rem; flex-wrap: wrap; }
    .iv-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #8B7A72; }
    .iv-req { color: #C0392B; }
    .iv-input { font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: .82rem; color: #3B2A24; background: #fff;
        border: 1px solid #F0E2D5; border-radius: 10px; padding: .55rem .65rem; width: 100%; margin: 0; }
    .iv-input:focus { outline: none; border-color: #F4845F; box-shadow: 0 0 0 3px rgba(244,132,95,.18); }
    .iv-input-sm { padding: .25rem .4rem; font-size: .75rem; width: auto; }
    .iv-search { position: relative; }
    .iv-search i { position: absolute; left: .65rem; top: 50%; transform: translateY(-50%); color: #F4845F; font-size: .8rem; }
    .iv-search .iv-input { padding-left: 1.9rem; }
    .iv-grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .75rem; margin-bottom: .75rem; }
    .iv-grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .75rem; margin-bottom: .75rem; }

    /* ── Table ── */
    .iv-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .82rem; }
    .iv-table thead th { background: #FDF1E6; font-size: .68rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .07em; color: #C0392B; padding: .75rem .7rem; white-space: nowrap;
        border-bottom: 1px solid #F0E2D5; position: sticky; top: 0; z-index: 1; }
    .iv-table tbody td { padding: .7rem; border-bottom: 1px solid #F7EFE7; vertical-align: middle; }
    .iv-table tbody tr:hover td { background: #FFFBF7; }
    .iv-ta-left { text-align: left; }
    .iv-ta-center { text-align: center; }
    .iv-ta-right { text-align: right; }
    .iv-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .iv-name { font-weight: 600; color: #3B2A24; }
    .iv-muted { color: #8B7A72; }
    .iv-date { font-size: .75rem; color: #8B7A72; white-space: nowrap; }
    .iv-reason { font-size: .76rem; color: #5B4740; }
    .iv-unit { font-weight: 400; color: #8B7A72; font-size: .7rem; }
    .iv-qty-in { font-weight: 700; color: #3B2A24; }
    .iv-qty-low { font-weight: 700; color: #F4845F; }
    .iv-qty-out { font-weight: 700; color: #C0392B; }
    .iv-amt-in { font-weight: 700; color: #2E7D5B; }
    .iv-amt-out { font-weight: 700; color: #C0392B; }

    .iv-badge { display: inline-block; font-size: .68rem; font-weight: 700; padding: .2rem .6rem; border-radius: 999px; white-space: nowrap; }
    .iv-badge-ok { background: #E6F4EC; color: #2E7D5B; border: 1px solid #C6E6D5; }
    .iv-badge-low { background: #FDF1E6; color: #A85A2B; border: 1px solid #F8D7B0; }
    .iv-badge-out { background: #FBE7E4; color: #C0392B; border: 1px solid #F6C9C1; }

    .iv-tag { display: inline-block; font-size: .65rem; font-weight: 700; padding: .15rem .5rem; border-radius: 999px; white-space: nowrap; }
    .iv-tag-manual { background: #F3EEEA; color: #6B5A52; }
    .iv-tag-order { background: #FDF1E6; color: #A85A2B; }
    .iv-tag-spoil { background: #FBE7E4; color: #C0392B; }
    .iv-tag-other { background: #EFE9F6; color: #6C4AA0; }

    .iv-mini { display: inline-flex; align-items: center; gap: .25rem; border-radius: 8px;
        padding: .3rem .6rem; font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: .7rem; font-weight: 700;
        cursor: pointer; border: 1px solid transparent; transition: background .12s ease, color .12s ease; }
    .iv-mini-in { background: #E6F4EC; color: #2E7D5B; border-color: #C6E6D5; }
    .iv-mini-in:hover { background: #2E7D5B; color: #fff; border-color: transparent; }
    .iv-mini-out { background: #FBE7E4; color: #C0392B; border-color: #F6C9C1; }
    .iv-mini-out:hover { background: #C0392B; color: #fff; border-color: transparent; }

    .iv-icon { width: 32px; height: 32px; display: inline-grid; place-items: center; border-radius: 9px;
        border: 1px solid #F0E2D5; background: #fff; cursor: pointer; font-size: .8rem;
        transition: background .12s ease, color .12s ease, border-color .12s ease; }
    .iv-icon-edit { color: #A85A2B; }
    .iv-icon-edit:hover { background: linear-gradient(135deg, #F8D7B0, #F4845F); color: #fff; border-color: transparent; }
    .iv-icon-del { color: #C0392B; }
    .iv-icon-del:hover { background: #C0392B; color: #fff; border-color: transparent; }

    .iv-empty { text-align: center; padding: 2.5rem 1rem; color: #8B7A72; }
    .iv-empty i { font-size: 1.6rem; color: #F6B49B; }
    .iv-empty p { font-family: 'Fraunces', Georgia, serif; font-size: 1rem; font-weight: 600; margin: .5rem 0 .2rem; color: #3B2A24; }
    .iv-empty span { font-size: .78rem; }

    /* ── Pagination ── */
    .iv-pager { display: grid; grid-template-columns: minmax(0,1fr); gap: .7rem;
        padding: .8rem 1rem; border-top: 1px solid #F0E2D5; background: #FFFBF7; }
    .iv-pager-info { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; font-size: .76rem; color: #8B7A72; font-weight: 600; }
    .iv-pager-size { display: inline-flex; align-items: center; gap: .35rem; }
    .iv-pager-nav { display: flex; align-items: center; gap: .3rem; flex-wrap: wrap; }
    .iv-page-list { display: flex; align-items: center; gap: .25rem; flex-wrap: wrap; }
    .iv-page-btn { min-width: 32px; height: 32px; padding: 0 .5rem; background: #fff;
        border: 1px solid #F0E2D5; border-radius: 9px; font-family: 'Karla', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: .76rem; font-weight: 700; color: #5B4740; cursor: pointer; }
    .iv-page-btn:hover:not(:disabled) { border-color: #F4845F; color: #C0392B; }
    .iv-page-btn:disabled { opacity: .4; cursor: not-allowed; }
    .iv-page-btn.is-active { background: linear-gradient(135deg, #F4845F, #EF8585); color: #fff; border-color: transparent; }
    .iv-page-gap { color: #C4B6AE; padding: 0 .15rem; }
    .iv-hidden-row { display: none !important; }

    /* ── Modals ── */
    .iv-modal { display: none; position: fixed; inset: 0; background: rgba(59,42,36,.5);
        z-index: 999; align-items: center; justify-content: center; padding: 1rem; }
    .iv-modal-box { background: #fff; border-radius: 18px; padding: 1.4rem; width: 100%; max-width: 560px;
        max-height: 90vh; overflow-y: auto; box-shadow: 0 30px 70px -40px rgba(59,42,36,.8); }
    .iv-modal-sm { max-width: 340px; text-align: center; }
    .iv-modal-head { display: flex; justify-content: space-between; align-items: center; gap: .75rem; margin-bottom: 1rem; }
    .iv-modal-title { font-family: 'Fraunces', Georgia, serif; font-size: 1.1rem; font-weight: 700; color: #3B2A24; margin: 0; }
    .iv-modal-x { background: none; border: none; font-size: 1.4rem; line-height: 1; cursor: pointer; color: #8B7A72; }
    .iv-modal-x:hover { color: #C0392B; }
    .iv-modal-note { font-size: .82rem; color: #5B4740; margin: 0 0 1rem; }
    .iv-modal-foot { display: flex; gap: .5rem; margin-top: 1.1rem; }
    .iv-confirm-ico { width: 46px; height: 46px; margin: 0 auto .8rem; display: grid; place-items: center;
        border-radius: 50%; background: #FBE7E4; color: #C0392B; font-size: 1.1rem; }
    .iv-confirm-text { font-family: 'Fraunces', Georgia, serif; font-size: 1rem; font-weight: 600; color: #3B2A24; margin: 0 0 1.2rem; }
    .iv-confirm-actions { display: flex; gap: .5rem; justify-content: center; flex-wrap: wrap; }
    .iv-confirm-actions form { margin: 0; }

    /* ── Desktop ── */
    @media (min-width: 768px) {
        .iv-header { grid-template-columns: minmax(0,1fr) auto; align-items: center; }
        .iv-actions { justify-content: flex-end; }
        {{-- 5 tiles now (the 4 status filters + Total Stock Value). --}}
        .iv-stats { grid-template-columns: repeat(5, minmax(0,1fr)); }
        .iv-pager { grid-template-columns: minmax(0,1fr) auto; align-items: center; }
        .iv-pager-nav { justify-content: flex-end; }
    }

    /* ── Mobile: rows become cards ── */
    @media (max-width: 767px) {
        .iv-title { font-size: 1.4rem; }
        .iv-field-grow { grid-column: span 1; }
        .iv-table-card { border: none; background: transparent; box-shadow: none; overflow: visible; }
        .iv-card-head { background: #fff; border: 1px solid #F0E2D5; border-radius: 14px; margin-bottom: .7rem; }
        .iv-table thead { display: none; }
        .iv-table, .iv-table tbody, .iv-table tr, .iv-table td { display: block; width: 100%; }
        .iv-table tbody tr.iv-row { background: #fff; border: 1px solid #F0E2D5; border-radius: 14px;
            box-shadow: 0 10px 26px -24px rgba(59,42,36,.5); padding: .35rem .15rem; margin-bottom: .7rem; }
        .iv-table tbody tr.iv-row:hover td { background: transparent; }
        .iv-table tbody td { display: grid; grid-template-columns: 42% minmax(0,58%); gap: .5rem;
            align-items: center; border-bottom: 1px dashed #F7EFE7; padding: .5rem .85rem; text-align: left !important; }
        .iv-table tbody td:last-child { border-bottom: none; }
        .iv-table tbody td::before { content: attr(data-label); font-size: .66rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em; color: #8B7A72; }
        .iv-empty-row td, .iv-nores-row td { padding: 0; background: #fff; border: 1px solid #F0E2D5; border-radius: 14px; }
        .iv-pager { background: #fff; border: 1px solid #F0E2D5; border-radius: 14px; }
        .iv-modal-foot { flex-direction: column-reverse; }
        .iv-modal-foot .iv-btn { width: 100%; }
    }
</style>
@endpush

@push('scripts')
<script>
    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'Add Inventory Item';
        document.getElementById('submitBtn').innerText = 'Add Item';
        document.getElementById('itemForm').action = `{{ route('admin.inventory.store') }}`;
        document.getElementById('formMethod').value = 'POST';
        document.getElementById('editingItemId').value = '';
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
        document.getElementById('editingItemId').value = btn.dataset.id;
        document.getElementById('itemName').value = btn.dataset.name || '';
        document.getElementById('itemCode').value = btn.dataset.code || '';
        document.getElementById('itemQuantity').value = btn.dataset.quantity || '';
        // If an existing record has a non-standard unit (e.g. "grams"), add it
        // temporarily so the dropdown can display it. Server-side validation still
        // requires the user to pick a standard unit before saving.
        var unitSelect = document.getElementById('itemUnit');
        var existingUnit = btn.dataset.unit || '';
        if (existingUnit && !Array.from(unitSelect.options).some(function (o) { return o.value === existingUnit; })) {
            var tmp = document.createElement('option');
            tmp.value = existingUnit;
            tmp.text = existingUnit + ' (legacy — pick a standard unit)';
            unitSelect.appendChild(tmp);
        }
        unitSelect.value = existingUnit;
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

    /* ── Search + status filter + pagination ── */
    var ivPage = 1;
    var ivPageSize = 15;
    var ivStatus = '';

    function ivAllRows() {
        return Array.prototype.slice.call(document.querySelectorAll('#ivBody tr.iv-row'));
    }

    function ivMatches() {
        var term = (document.getElementById('searchInput').value || '').toLowerCase();
        return ivAllRows().filter(function (row) {
            var okText = !term || row.innerText.toLowerCase().includes(term);
            var okStatus = !ivStatus || row.dataset.status === ivStatus;
            return okText && okStatus;
        });
    }

    function searchTable() {
        ivPage = 1;
        ivRender();
    }

    function ivSetStatus(value) {
        ivStatus = value || '';
        var select = document.getElementById('ivStatus');
        if (select && select.value !== ivStatus) select.value = ivStatus;
        document.querySelectorAll('.iv-stat').forEach(function (s) {
            s.classList.toggle('is-active', (s.dataset.status || '') === ivStatus);
        });
        ivPage = 1;
        ivRender();
    }

    function ivReset() {
        document.getElementById('searchInput').value = '';
        ivSetStatus('');
    }

    function ivRender() {
        var pager = document.getElementById('ivPager');
        if (!pager) return;

        var all = ivAllRows();
        var matched = ivMatches();

        var pages = Math.max(1, Math.ceil(matched.length / ivPageSize));
        if (ivPage > pages) ivPage = pages;
        if (ivPage < 1) ivPage = 1;

        var start = (ivPage - 1) * ivPageSize;
        var end = Math.min(start + ivPageSize, matched.length);

        all.forEach(function (row) { row.classList.add('iv-hidden-row'); });
        matched.slice(start, end).forEach(function (row) { row.classList.remove('iv-hidden-row'); });

        var noRes = document.getElementById('ivNoResults');
        if (noRes) noRes.hidden = !(all.length > 0 && matched.length === 0);

        pager.hidden = matched.length === 0;
        document.getElementById('ivPagerRange').innerText = matched.length === 0
            ? 'No items'
            : 'Showing ' + (start + 1) + '–' + end + ' of ' + matched.length + ' items';

        document.getElementById('ivPrev').disabled = (ivPage === 1);
        document.getElementById('ivNext').disabled = (ivPage === pages);

        var list = document.getElementById('ivPageList');
        list.innerHTML = '';
        ivPageNumbers(ivPage, pages).forEach(function (p) {
            if (p === '…') {
                var gap = document.createElement('span');
                gap.className = 'iv-page-gap';
                gap.innerText = '…';
                list.appendChild(gap);
                return;
            }
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'iv-page-btn' + (p === ivPage ? ' is-active' : '');
            btn.innerText = p;
            btn.onclick = function () { ivGo(p); };
            list.appendChild(btn);
        });
    }

    function ivPageNumbers(current, pages) {
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

    function ivGo(page) {
        ivPage = page;
        ivRender();
        var card = document.querySelector('.iv-table-card');
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var sizeSelect = document.getElementById('ivPageSize');
        if (sizeSelect) {
            ivPageSize = parseInt(sizeSelect.value, 10) || 15;
            sizeSelect.addEventListener('change', function () {
                ivPageSize = parseInt(this.value, 10) || 15;
                ivPage = 1;
                ivRender();
            });
        }
        ivSetStatus('');
    });

    var hasErrors = "{{ $errors->any() ? '1' : '0' }}";
    if (hasErrors === '1') {
        document.addEventListener('DOMContentLoaded', function() {
            /*
             * The failed submission's own context, not the JS click state
             * that a redirect always loses. editing_item_id came back through
             * old() (a plain form field flashed by Laravel's automatic
             * withInput() on a validate() failure) exactly like item_name or
             * item_code did — its presence is what says this was an EDIT, so
             * the reopened modal points at the right item's PUT route instead
             * of silently falling back to Add and creating a duplicate item
             * on the next attempt.
             */
            var editingId = document.getElementById('editingItemId').value;

            if (editingId) {
                document.getElementById('modalTitle').innerText = 'Edit Inventory Item';
                document.getElementById('submitBtn').innerText = 'Update';
                document.getElementById('itemForm').action = '/admin/inventory/' + editingId;
                document.getElementById('formMethod').value = 'PUT';
            }

            document.getElementById('itemModal').style.display = 'flex';
        });
    }
</script>
@endpush

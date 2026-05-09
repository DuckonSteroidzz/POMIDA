@extends('admin.layout')

@section('title', '{{ isset($menuItem) ? "Edit" : "New" }} Menu Item - Peachy Admin')

@section('content')

<p class="page-title">{{ isset($menuItem) ? 'Edit Menu Item' : 'New Menu Item' }}</p>

<div class="content-card" style="max-width: 700px;">

    @if ($errors->any())
        <div style="background:#f8d7da; color:#721c24; padding:0.6rem 0.85rem; border-radius:8px; font-size:0.82rem; margin-bottom:1rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- Form action depends on mode --}}
    @if(isset($menuItem))
        <form action="{{ route('admin.menu-items.update', $menuItem->id) }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')
    @else
        <form action="{{ route('admin.new-menu-item.post') }}" method="POST" enctype="multipart/form-data">
            @csrf
    @endif

        {{-- Item Name + Categories Row --}}
        <div style="display: flex; gap: 0.75rem; align-items: flex-start; margin-bottom: 0.85rem;">
            <div style="flex: 1;">
                <label class="form-label-custom">Item Name</label>
                <input type="text" name="name" class="form-control-custom" placeholder="Enter item name" 
                    value="{{ old('name', $menuItem->name ?? '') }}" required style="margin-bottom:0;">
            </div>
            <div style="width: 160px;">
                <label class="form-label-custom">Main Category</label>
                <select name="category_id" class="form-control-custom" required style="margin-bottom:0;">
                    <option value="">-- Select --</option>
                    @if(isset($categories))
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" 
                                {{ old('category_id', $menuItem->category_id ?? '') == $category->id ? 'selected' : '' }}>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    @endif
                </select>
            </div>
            <div style="width: 160px;">
                <label class="form-label-custom">Sub Category</label>
                <select name="subcategory_id" class="form-control-custom" style="margin-bottom:0;">
                    <option value="">-- Optional --</option>
                    @if(isset($subcategories))
                        @foreach($subcategories as $sub)
                            <option value="{{ $sub->id }}" 
                                {{ old('subcategory_id', $menuItem->subcategory_id ?? '') == $sub->id ? 'selected' : '' }}>
                                {{ $sub->name }}
                            </option>
                        @endforeach
                    @endif
                </select>
            </div>
        </div>

        {{-- Item Description --}}
        <div style="margin-bottom: 0.85rem;">
            <label class="form-label-custom" style="background-color: #C0392B; color: white; padding: 0.4rem 0.85rem; border-radius: 6px 6px 0 0; display: block; margin-bottom: 0;">
                Item Description
            </label>
            <textarea name="description" rows="4" class="form-control-custom"
                style="border-radius: 0 0 8px 8px; resize: none; margin-bottom: 0;"
                placeholder="Enter a short description of the item here">{{ old('description', $menuItem->description ?? '') }}</textarea>
        </div>

        {{-- Price + Cost Row --}}
        <div style="display: flex; gap: 0.75rem; margin-bottom: 0.85rem;">
            <div style="flex: 1;">
                <label class="form-label-custom">Price (₱)</label>
                <input type="number" name="price" class="form-control-custom" placeholder="0.00" 
                    value="{{ old('price', $menuItem->price ?? '') }}" step="0.01" min="0" required style="margin-bottom:0;">
            </div>
            <div style="flex: 1;">
                <label class="form-label-custom">Cost (₱) — Optional</label>
                <input type="number" name="cost" class="form-control-custom" placeholder="0.00" 
                    value="{{ old('cost', $menuItem->cost ?? '') }}" step="0.01" min="0" style="margin-bottom:0;">
            </div>
        </div>

        {{-- Inventory Auto-Deduct Link --}}
        <div style="background:#fef5f2; padding:0.75rem; border-radius:8px; margin-bottom: 0.85rem; border-left: 3px solid #F4845F;">
            <p style="font-size:0.78rem; font-weight:600; color:#C0392B; margin:0 0 0.5rem 0;">
                <i class="bi bi-link-45deg"></i> Inventory Auto-Deduct (Optional)
            </p>
            <p style="font-size:0.7rem; color:#888; margin:0 0 0.5rem 0;">
                Link this menu item to an inventory item so stock auto-deducts when an order is completed.
            </p>
            
            <div style="display: flex; gap: 0.75rem; margin-bottom: 0;">
                <div style="flex: 2;">
                    <label class="form-label-custom">Inventory Item</label>
                    <select name="inventory_item_id" class="form-control-custom" style="margin-bottom:0;">
                        <option value="">-- No link --</option>
                        @if(isset($inventoryItems))
                            @foreach($inventoryItems as $invItem)
                                <option value="{{ $invItem->id }}" 
                                    {{ old('inventory_item_id', $menuItem->inventory_item_id ?? '') == $invItem->id ? 'selected' : '' }}>
                                    {{ $invItem->item_name }} ({{ $invItem->quantity }} {{ $invItem->unit }} available)
                                </option>
                            @endforeach
                        @endif
                    </select>
                </div>
                <div style="flex: 1;">
                    <label class="form-label-custom">Amount Used Per Order</label>
                    <input type="number" name="inventory_amount_used" class="form-control-custom" 
                        placeholder="0.00" step="0.001" min="0"
                        value="{{ old('inventory_amount_used', $menuItem->inventory_amount_used ?? '') }}"
                        style="margin-bottom:0;">
                </div>
            </div>
        </div>

        {{-- Current Image (Edit mode only) --}}
        @if(isset($menuItem) && $menuItem->image)
            <div style="margin-bottom: 0.85rem;">
                <label class="form-label-custom">Current Image</label>
                <div>
                    <img src="{{ asset($menuItem->image) }}" 
                        style="width: 120px; height: 120px; border-radius: 8px; object-fit: cover; border: 1px solid #ddd;">
                </div>
            </div>
        @endif

        {{-- Image Upload --}}
        <div style="margin-bottom: 1rem;">
            <label class="form-label-custom">
                {{ isset($menuItem) && $menuItem->image ? 'Replace Image (Optional)' : 'Item Image (Optional)' }}
            </label>
            <input type="file" name="image" class="form-control-custom" accept="image/*" style="margin-bottom:0; padding: 0.45rem 0.85rem;">
            <small style="color: #888; font-size: 0.7rem;">JPG, PNG, or WEBP. Max 2MB.</small>
        </div>

        {{-- Action Buttons --}}
        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn-primary-custom" style="flex: 1; padding: 0.65rem; font-size: 0.88rem;">
                {{ isset($menuItem) ? 'Update Item' : 'Add Item' }}
            </button>
            <a href="{{ route('admin.menu-items') }}" class="btn-danger-custom" 
                style="padding: 0.65rem 1.5rem; font-size: 0.88rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">
                Cancel
            </a>
        </div>

    </form>

</div>

@endsection
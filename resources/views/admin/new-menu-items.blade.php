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

    @if(isset($menuItem))
    <div style="background:#e8f4fd; color:#0c5a8a; padding:0.55rem 0.85rem; border-radius:8px; font-size:0.78rem; margin-bottom:1rem; border-left:3px solid #2196F3;">
        <i class="bi bi-info-circle"></i>
        To set ingredients (e.g. Pizza Dough, Cheese, Tomato Sauce, Pepperoni), scroll down to the
        <strong>Recipe Ingredients</strong> section. You can add multiple ingredients to one menu item.
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

        {{-- Legacy Single-Ingredient Link (collapsed). Kept only for backward compat
             with items created before the Recipe Ingredients feature existed. --}}
        <details style="margin-bottom: 0.85rem; background:#f4f4f4; border-radius:8px; padding:0.55rem 0.75rem;">
            <summary style="cursor:pointer; font-size:0.75rem; font-weight:600; color:#666;">
                <i class="bi bi-archive"></i> Legacy Single-Ingredient Link (only used if no Recipe Ingredients are set)
            </summary>
            <p style="font-size:0.7rem; color:#888; margin:0.5rem 0;">
                This older option links the item to exactly one inventory entry. New items should leave this empty
                and use the <strong>Recipe Ingredients</strong> section below instead.
            </p>

            <div style="display: flex; gap: 0.75rem; margin:0;">
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
        </details>

        {{-- Current Image (Edit mode only) --}}
        @if(isset($menuItem) && $menuItem->image)
            <div style="margin-bottom: 0.85rem;">
                <label class="form-label-custom">Current Image</label>
                <div>
                    <img src="{{ \App\Support\Img::url($menuItem->image) }}"
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

@if(isset($menuItem))
<div class="content-card" style="max-width: 700px; margin-top: 1rem; border:2px solid #F4845F;">
    <p style="font-size:1rem; font-weight:700; color:#C0392B; margin-bottom:0.35rem;">
        <i class="bi bi-list-ul"></i> Recipe Ingredients
        <span style="font-size:0.7rem; font-weight:500; color:#666; margin-left:0.4rem;">(add as many as you need)</span>
    </p>
    <p style="font-size:0.75rem; color:#555; margin-bottom:0.75rem;">
        Every ingredient listed here is deducted from inventory whenever this menu item is ordered.
        For example, Pepperoni Pizza might use Pizza Dough, Cheese, Tomato Sauce, and Pepperoni.
    </p>

    @php $recipe = $menuItem->recipeIngredients()->with('inventory')->get(); @endphp

    @if($recipe->isEmpty())
        <div style="background:#fff8e1; color:#7a5c00; padding:0.5rem 0.75rem; border-radius:6px; font-size:0.75rem; margin-bottom:0.75rem;">
            <i class="bi bi-exclamation-triangle"></i> No recipe ingredients assigned yet.
        </div>
    @else
        <table style="width:100%; font-size:0.78rem; margin-bottom:0.75rem;">
            <thead>
                <tr style="text-align:left; color:#888; font-size:0.7rem;">
                    <th style="padding:0.3rem 0.4rem;">Ingredient</th>
                    <th style="padding:0.3rem 0.4rem;">Quantity</th>
                    <th style="padding:0.3rem 0.4rem; text-align:right;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($recipe as $row)
                <tr style="border-top:1px solid #f0f0f0;">
                    <td style="padding:0.35rem 0.4rem;">{{ $row->inventory->item_name ?? '(removed)' }}</td>
                    <td style="padding:0.35rem 0.4rem;">{{ rtrim(rtrim(number_format($row->quantity_used, 3), '0'), '.') }} {{ $row->inventory->unit ?? '' }}</td>
                    <td style="padding:0.35rem 0.4rem; text-align:right;">
                        <form action="{{ route('admin.menu-items.ingredients.delete', [$menuItem->id, $row->id]) }}" method="POST" style="margin:0; display:inline;" onsubmit="return confirm('Remove this ingredient?')">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:#C0392B; color:white; border:none; border-radius:6px; padding:0.2rem 0.5rem; font-size:0.7rem; cursor:pointer;">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <form action="{{ route('admin.menu-items.ingredients.add', $menuItem->id) }}" method="POST" style="display:flex; gap:0.5rem; align-items:flex-end; flex-wrap:wrap;">
        @csrf
        <div style="flex:2; min-width:180px;">
            <label class="form-label-custom">Ingredient</label>
            <select name="inventory_id" class="form-control-custom" required style="margin-bottom:0;">
                <option value="">-- Select --</option>
                @if(isset($inventoryItems))
                    @foreach($inventoryItems as $invItem)
                        <option value="{{ $invItem->id }}">
                            {{ $invItem->item_name }} ({{ $invItem->quantity }} {{ $invItem->unit }} on hand)
                        </option>
                    @endforeach
                @endif
            </select>
        </div>
        <div style="flex:1; min-width:120px;">
            <label class="form-label-custom">Quantity Used</label>
            <input type="number" name="quantity_used" class="form-control-custom" step="0.001" min="0.001" required style="margin-bottom:0;">
        </div>
        <button type="submit" class="btn-primary-custom" style="padding:0.55rem 1rem;">
            <i class="bi bi-plus"></i> Add
        </button>
    </form>
</div>
@endif

@endsection
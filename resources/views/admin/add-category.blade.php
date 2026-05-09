@extends('admin.layout')

@section('title', 'Categories - Peachy Admin')

@section('content')

<p class="page-title">Categories & Subcategories</p>

@if ($errors->any())
<div style="background:#f8d7da;color:#721c24;padding:0.6rem 0.85rem;border-radius:8px;font-size:0.82rem;margin-bottom:1rem;">
    @foreach ($errors->all() as $error)
    <div>{{ $error }}</div>
    @endforeach
</div>
@endif

@if(session('success'))
<div class="alert-success-custom">
    <i class="bi bi-check-circle"></i> {{ session('success') }}
</div>
@endif

{{-- Add Forms Row --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">

    <div class="content-card" style="margin-bottom:0;">
        <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:0.75rem;">Category</p>
        <form action="{{ route('admin.add-category.post') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div>

                <input type="text" name="name" class="form-control-custom" placeholder="Enter Name" value="{{ old('name') }}" required>
            </div>
            <div>
                <label class="form-label-custom">Category Image</label>
                <input type="file" name="image" class="form-control-custom" accept="image/*">
            </div>
            <button type="submit" class="btn-primary-custom" style="width:100%;">
                <i class="bi bi-plus-circle"></i> Add Category
            </button>
        </form>
    </div>

    <div class="content-card" style="margin-bottom:0;">
        <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:0.75rem;">Subcategory</p>
        <form action="{{ route('admin.add-subcategory.post') }}" method="POST">
            @csrf
            <div>

                <select name="category_id" class="form-control-custom" required>
                    <option value=""> Select category </option>
                    @if(isset($categories))
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                    @endif
                </select>
            </div>
            <div>
                <label class="form-label-custom">Subcategory Name </label>
                <input type="text" name="name" class="form-control-custom" placeholder="Select" value="{{ old('name') }}" required>
            </div>
            <button type="submit" class="btn-primary-custom" style="width:100%;">
                <i class="bi bi-plus-circle"></i> Add Subcategory
            </button>
        </form>
    </div>

</div>

{{-- Lists Row --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">

    {{-- Categories List --}}
    <div class="content-card" style="margin-bottom:0;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
            <p style="font-size:0.9rem;font-weight:700;color:#333;margin:0;">Categories ({{ isset($categories) ? count($categories) : 0 }})</p>
            <div class="search-wrapper">
                <i class="bi bi-search"></i>
                <input type="text" class="search-input" placeholder="Search..." id="searchCat" onkeyup="searchTable('catTable', 'searchCat')" style="width:130px;">
            </div>
        </div>
        <table class="table-custom" id="catTable">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Image</th>
                    <th style=>Items</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
                @if(isset($categories) && count($categories) > 0)
                @foreach($categories as $cat)
                <tr>
                    <td style="font-weight:500;">{{ $cat->name }}</td>
                    <td>
                        @if($cat->image)
                        <img src="{{ asset($cat->image) }}" style="width:36px;height:36px;border-radius:6px;object-fit:cover;">
                        @else
                        <div style="width:36px;height:36px;border-radius:6px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-image" style="color:#ccc;font-size:0.85rem;"></i>
                        </div>
                        @endif
                    </td>
                    <td style="text-align:center;color:#888;font-size:0.75rem;">{{ $cat->menuItems->count() }} items</td>
                    <td>
                        <form action="{{ route('admin.add-category.delete', $cat->id) }}" method="POST" onsubmit="return confirm('Delete category {{ $cat->name }}?')" style="margin:0;">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-danger-custom"><i class="bi bi-trash3"></i></button>
                        </form>
                    </td>
                </tr>
                @endforeach
                @else
                <tr>
                    <td colspan="4" style="text-align:center;color:#aaa;padding:2rem;">No categories yet</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- Subcategories List --}}
    <div class="content-card" style="margin-bottom:0;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
            <p style="font-size:0.9rem;font-weight:700;color:#333;margin:0;">Subcategories ({{ isset($subcategories) ? count($subcategories) : 0 }})</p>
            <div class="search-wrapper">
                <i class="bi bi-search"></i>
                <input type="text" class="search-input" placeholder="Search..." id="searchSub" onkeyup="searchTable('subTable', 'searchSub')" style="width:130px;">
            </div>
        </div>
        <table class="table-custom" id="subTable">
            <thead>
                <tr>
                    <th>Subcategory</th>
                    <th>Category</th>
                    <th style="text-align:center;">Delete</th>
                </tr>
            </thead>
            <tbody>
                @if(isset($subcategories) && count($subcategories) > 0)
                @foreach($subcategories as $sub)
                <tr>
                    <td style="font-weight:500;">{{ $sub->name }}</td>
                    <td style="color:#666;font-size:0.78rem;">{{ $sub->category->name ?? 'N/A' }}</td>
                    <td style="text-align:center;">
                        <form action="{{ route('admin.add-subcategory.delete', $sub->id) }}" method="POST" onsubmit="return confirm('Delete subcategory {{ $sub->name }}?')" style="margin:0;">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-danger-custom"><i class="bi bi-trash3"></i></button>
                        </form>
                    </td>
                </tr>
                @endforeach
                @else
                <tr>
                    <td colspan="3" style="text-align:center;color:#aaa;padding:2rem;">No subcategories yet</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

</div>

@endsection

@push('scripts')
<script>
    function searchTable(tableId, inputId) {
        const input = document.getElementById(inputId).value.toLowerCase();
        const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
        });
    }
</script>
@endpush
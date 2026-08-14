@extends('admin.layout')

@section('title', 'Add Subcategory - Peachy Admin')

@section('content')

<p class="page-title">{{ isset($subcategory) ? 'Edit Subcategory' : 'Sub Category Items' }}</p>

{{-- Add/Edit Form --}}
<div class="content-card">
    <div class="search-wrapper" style="margin-bottom: 1rem;">
        <i class="bi bi-search"></i>
        <input type="text" class="search-input" placeholder="Search..." id="searchInput" onkeyup="searchTable()" style="width: 250px;">
    </div>

    @if(isset($subcategory))
        {{-- EDIT MODE --}}
        <form action="{{ route('admin.add-subcategory.update', $subcategory->id) }}" method="POST" style="display: flex; gap: 0.75rem; align-items: flex-end; margin-bottom: 0;">
            @csrf
            @method('PUT')
            <div style="flex: 1;">
                <label class="form-label-custom">Parent Category</label>
                <select name="category_id" class="form-control-custom" required style="margin-bottom: 0;">
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ $subcategory->category_id == $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div style="flex: 1;">
                <label class="form-label-custom">Subcategory Name</label>
                <input type="text" name="name" class="form-control-custom" value="{{ old('name', $subcategory->name) }}" required style="margin-bottom: 0;">
            </div>
            <button type="submit" class="btn-primary-custom" style="padding: 0.55rem 1.25rem; white-space: nowrap;">Update</button>
            <a href="{{ route('admin.add-subcategory') }}" class="btn-danger-custom" style="padding: 0.55rem 1.25rem; white-space: nowrap; text-decoration: none; display: inline-flex; align-items: center;">Cancel</a>
        </form>
    @else
        {{-- ADD MODE --}}
        <form action="{{ route('admin.add-subcategory.post') }}" method="POST" style="display: flex; gap: 0.75rem; align-items: flex-end; margin-bottom: 0;">
            @csrf
            <div style="flex: 1;">
                <label class="form-label-custom">Parent Category</label>
                <select name="category_id" class="form-control-custom" required style="margin-bottom: 0;">
                    <option value="">-- Select category --</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div style="flex: 1;">
                <label class="form-label-custom">Subcategory Name</label>
                <input type="text" name="name" class="form-control-custom" placeholder="e.g., Classic, Specialty" value="{{ old('name') }}" required style="margin-bottom: 0;">
            </div>
            <button type="submit" class="btn-primary-custom" style="padding: 0.55rem 1.25rem; white-space: nowrap;">Add Subcategory</button>
        </form>
    @endif
</div>

{{-- Errors --}}
@if ($errors->any())
    <div style="background:#f8d7da; color:#721c24; padding:0.6rem 0.85rem; border-radius:8px; font-size:0.82rem; margin-bottom:1rem;">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

{{-- Subcategories Table --}}
<div class="content-card" style="overflow-x: auto;">
    <table class="table-custom" id="subcategoryTable">
        <thead>
            <tr>
                <th>Subcategory</th>
                <th>Parent Category</th>
                <th>Edit</th>
                <th>Delete</th>
            </tr>
        </thead>
        <tbody>
            @if(isset($subcategories) && count($subcategories) > 0)
                @foreach($subcategories as $sub)
                <tr>
                    <td style="font-weight: 500;">{{ $sub->name }}</td>
                    <td>{{ $sub->category->name ?? 'N/A' }}</td>
                    <td>
                        <a href="{{ route('admin.add-subcategory.edit', $sub->id) }}" class="btn-edit-custom" style="text-decoration: none; display: inline-block;">
                            <i class="bi bi-pencil-square"></i>
                        </a>
                    </td>
                    <td>
                        <form action="{{ route('admin.add-subcategory.delete', $sub->id) }}" method="POST" onsubmit="return confirm('Delete this subcategory?')" style="margin: 0;">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-danger-custom">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="4" style="text-align: center; color: #aaa; padding: 2rem;">No subcategories yet. Add your first subcategory above!</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

@endsection

@push('scripts')
<script>
    function searchTable() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#subcategoryTable tbody tr');
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
        });
    }
</script>
@endpush
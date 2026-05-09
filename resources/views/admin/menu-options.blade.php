@extends('admin.layout')

@section('title', 'Menu Options - Peachy Admin')

@section('content')

<p class="page-title">Menu Options</p>

@if(session('success'))
<div class="alert-success-custom"><i class="bi bi-check-circle"></i> {{ session('success') }}</div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

    {{-- LEFT: Options List + Add Form --}}
    <div>
        <div class="content-card" style="margin-bottom:1rem;">
            <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:0.75rem;">Add New Option</p>
            <form action="{{ route('admin.menu-options.post') }}" method="POST">
                @csrf
                <div style="display:grid;grid-template-columns:1fr auto auto;gap:0.5rem;align-items:flex-end;">
                    <div>
                        <label class="form-label-custom">Option Name *</label>
                        <input type="text" name="name" class="form-control-custom" placeholder="e.g. Extra Cheese" required style="margin-bottom:0;">
                    </div>
                    <div>
                        <label class="form-label-custom">Price (₱)</label>
                        <input type="number" name="price" class="form-control-custom" placeholder="0.00" step="0.01" min="0" value="0" style="margin-bottom:0;width:90px;">
                    </div>
                    <button type="submit" class="btn-primary-custom" style="padding:0.55rem 1rem;">
                        <i class="bi bi-plus"></i> Add
                    </button>
                </div>
            </form>
        </div>

        <div class="content-card">
            <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:0.75rem;">
                All Options ({{ count($options) }})
                <span style="font-size:0.72rem;color:#888;font-weight:400;margin-left:0.5rem;">Click a menu item on the right to assign options</span>
            </p>
            <div id="optionsList">
                @if(count($options) > 0)
                    @foreach($options as $option)
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 0.75rem;background:#f9f9f9;border-radius:8px;margin-bottom:0.4rem;border:2px solid transparent;" id="opt-{{ $option->id }}">
                        <div style="display:flex;align-items:center;gap:0.75rem;">
                            <input type="checkbox" class="option-check" data-id="{{ $option->id }}" style="width:16px;height:16px;accent-color:#F4845F;">
                            <div>
                                <span style="font-size:0.82rem;font-weight:600;color:#333;">{{ $option->name }}</span>
                                @if($option->additional_price > 0)
                                <span style="font-size:0.72rem;color:#F4845F;margin-left:0.4rem;">+₱{{ number_format($option->additional_price, 2) }}</span>
                                @else
                                <span style="font-size:0.72rem;color:#aaa;margin-left:0.4rem;">Free</span>
                                @endif
                            </div>
                        </div>
                        <form action="{{ route('admin.menu-options.delete', $option->id) }}" method="POST" onsubmit="return confirm('Delete this option?')" style="margin:0;">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:#C0392B;color:white;border:none;border-radius:6px;padding:0.25rem 0.5rem;font-size:0.72rem;cursor:pointer;">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                    @endforeach

                    <div id="assignSection" style="display:none;margin-top:0.75rem;">
                        <button onclick="saveAssignment()" class="btn-primary-custom" style="width:100%;">
                            <i class="bi bi-check-circle"></i> Save Options to <span id="selectedItemName">Item</span>
                        </button>
                    </div>
                @else
                    <div style="text-align:center;padding:2rem;color:#aaa;font-size:0.85rem;">
                        <i class="bi bi-list-check" style="font-size:2rem;display:block;margin-bottom:0.5rem;color:#ddd;"></i>
                        No options yet. Add one above!
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- RIGHT: Categories + Menu Items --}}
    <div class="content-card">
        <p style="font-size:0.9rem;font-weight:700;color:#333;margin-bottom:0.75rem;">Categories & Menu Items</p>
        <p style="font-size:0.75rem;color:#888;margin-bottom:0.75rem;">Click a menu item to assign options to it.</p>

        @if(count($categories) > 0)
            @foreach($categories as $category)
            <div style="margin-bottom:0.75rem;">
                <div style="background:#C0392B;color:white;padding:0.4rem 0.75rem;border-radius:6px;font-size:0.78rem;font-weight:600;margin-bottom:0.4rem;">
                    {{ $category->name }}
                </div>
                @if($category->menuItems->count() > 0)
                    @foreach($category->menuItems as $item)
                    <div class="menu-item-row" data-id="{{ $item->id }}" data-name="{{ $item->name }}"
                        data-options="{{ $item->options->pluck('id')->toJson() }}"
                        onclick="selectMenuItem(this)"
                        style="display:flex;align-items:center;justify-content:space-between;padding:0.4rem 0.75rem;background:#fde8de;border-radius:6px;margin-bottom:0.3rem;cursor:pointer;border:2px solid transparent;">
                        <span style="font-size:0.8rem;font-weight:500;color:#333;">{{ $item->name }}</span>
                        <span style="font-size:0.68rem;color:#F4845F;">{{ $item->options->count() }} option(s)</span>
                    </div>
                    @endforeach
                @else
                    <div style="padding:0.3rem 0.75rem;font-size:0.75rem;color:#aaa;">No items yet</div>
                @endif
            </div>
            @endforeach
        @else
            <div style="text-align:center;padding:2rem;color:#aaa;font-size:0.85rem;">No categories yet</div>
        @endif
    </div>

</div>

@endsection

@push('scripts')
<script>
let selectedItemId = null;

function selectMenuItem(el) {
    // Remove active from all
    document.querySelectorAll('.menu-item-row').forEach(r => {
        r.style.borderColor = 'transparent';
        r.style.background = '#fde8de';
    });

    // Highlight selected
    el.style.borderColor = '#F4845F';
    el.style.background = '#fce0d0';

    selectedItemId = el.dataset.id;
    const itemName = el.dataset.name;
    const assignedOptions = JSON.parse(el.dataset.options);

    // Update assign button
    document.getElementById('selectedItemName').textContent = itemName;
    document.getElementById('assignSection').style.display = 'block';

    // Check/uncheck options based on assigned
    document.querySelectorAll('.option-check').forEach(cb => {
        cb.checked = assignedOptions.includes(parseInt(cb.dataset.id));
    });
}

function saveAssignment() {
    if (!selectedItemId) return;

    const checkedOptions = [];
    document.querySelectorAll('.option-check:checked').forEach(cb => {
        checkedOptions.push(cb.dataset.id);
    });

    fetch('/admin/menu-options/assign/' + selectedItemId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ option_ids: checkedOptions })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Update option count display
            const itemRow = document.querySelector('[data-id="' + selectedItemId + '"]');
            if (itemRow) {
                itemRow.querySelector('span:last-child').textContent = checkedOptions.length + ' option(s)';
                itemRow.dataset.options = JSON.stringify(checkedOptions.map(Number));
            }
            // Show success message
            const btn = document.querySelector('#assignSection button');
            btn.innerHTML = '<i class="bi bi-check2"></i> Saved!';
            btn.style.background = '#4CAF50';
            setTimeout(() => {
                btn.innerHTML = '<i class="bi bi-check-circle"></i> Save Options to ' + document.getElementById('selectedItemName').textContent;
                btn.style.background = '';
            }, 2000);
        }
    })
    .catch(err => console.error(err));
}
</script>
@endpush
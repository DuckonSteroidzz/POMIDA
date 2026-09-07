@extends('admin.layout')

@section('title', 'Add Subcategory - Peachy Admin')

@section('content')

<link href="/vendor/gfonts.css" rel="stylesheet">

<style>
    .pchy{--p1:#F8D7B0;--p2:#F6B49B;--p3:#EF8585;--p4:#F4845F;--p5:#C0392B;--p6:#8B1A1A;font-family:'Karla',system-ui,sans-serif;color:#4a3b36}
    .pchy *{box-sizing:border-box}
    .pchy-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:.75rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:2px dashed rgba(244,132,95,.35)}
    .pchy-title{font-family:'Fraunces',Georgia,serif;font-size:1.7rem;font-weight:600;line-height:1.15;margin:0;color:var(--p6)}
    .pchy-sub{margin:.3rem 0 0;font-size:.85rem;color:#8d7a73}
    .pchy-chip{background:linear-gradient(135deg,var(--p1),var(--p2));color:var(--p6);font-size:.74rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:.4rem .7rem;border-radius:999px}
    .pchy-alert{background:#fff2f0;border:1px solid rgba(192,57,43,.28);border-left:5px solid var(--p5);color:var(--p6);padding:.75rem 1rem;border-radius:12px;font-size:.85rem;margin-bottom:1.1rem}
    .pchy-alert div+div{margin-top:.25rem}
    .pchy-card{background:#fff;border:1px solid rgba(246,180,155,.45);border-radius:18px;padding:1.15rem;box-shadow:0 10px 26px -18px rgba(139,26,26,.35);margin-bottom:1.1rem}
    .pchy-card-hd{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.9rem}
    .pchy-card-t{font-family:'Fraunces',Georgia,serif;font-size:1.05rem;font-weight:600;color:var(--p6);margin:0;display:flex;align-items:center;gap:.5rem}
    .pchy-ico{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:linear-gradient(135deg,var(--p2),var(--p4));color:#fff;font-size:.85rem}
    .pchy-count{font-size:.72rem;font-weight:700;color:var(--p5);background:rgba(248,215,176,.55);padding:.25rem .55rem;border-radius:999px}
    .pchy-form{display:grid;grid-template-columns:1fr 1fr auto;gap:.75rem;align-items:end}
    .pchy-form .pchy-actions{display:flex;gap:.5rem}
    .pchy-label{display:block;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9a837b;margin-bottom:.35rem}
    .pchy-in{width:100%;font-family:'Karla',sans-serif;font-size:.92rem;color:#4a3b36;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:11px;padding:.65rem .8rem;transition:.18s}
    .pchy-in:focus{outline:none;border-color:var(--p4);background:#fff;box-shadow:0 0 0 3px rgba(244,132,95,.18)}
    .pchy-btn{font-family:'Karla',sans-serif;font-size:.9rem;font-weight:700;border:0;cursor:pointer;border-radius:11px;padding:.7rem 1.15rem;color:#fff;white-space:nowrap;text-decoration:none;background:linear-gradient(135deg,var(--p4),var(--p5));box-shadow:0 8px 18px -10px rgba(192,57,43,.7);transition:.18s;display:inline-flex;align-items:center;justify-content:center;gap:.45rem}
    .pchy-btn:hover{filter:brightness(1.06);transform:translateY(-1px);color:#fff}
    .pchy-btn.ghost{background:#fff;color:var(--p5);border:1.5px solid rgba(192,57,43,.35);box-shadow:none}
    .pchy-btn.ghost:hover{background:rgba(239,133,133,.14);color:var(--p6)}
    .pchy-search{position:relative;display:flex;align-items:center;max-width:230px;width:100%}
    .pchy-search i{position:absolute;left:.65rem;color:var(--p4);font-size:.8rem}
    .pchy-search input{width:100%;font-family:'Karla',sans-serif;font-size:.84rem;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:999px;padding:.45rem .8rem .45rem 1.9rem}
    .pchy-search input:focus{outline:none;border-color:var(--p4)}
    .pchy-tblwrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .pchy-tbl{width:100%;border-collapse:separate;border-spacing:0 .4rem;font-size:.88rem}
    .pchy-tbl th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:#9a837b;text-align:left;padding:.35rem .7rem;font-weight:700;white-space:nowrap}
    .pchy-tbl td{background:#fffaf6;padding:.6rem .7rem;vertical-align:middle;border-top:1px solid rgba(246,180,155,.35);border-bottom:1px solid rgba(246,180,155,.35)}
    .pchy-tbl td:first-child{border-left:1px solid rgba(246,180,155,.35);border-radius:12px 0 0 12px}
    .pchy-tbl td:last-child{border-right:1px solid rgba(246,180,155,.35);border-radius:0 12px 12px 0}
    .pchy-tbl tbody tr:hover td{background:#fff4ec}
    .pchy-name{font-weight:600;color:#463430}
    .pchy-badge{display:inline-block;font-size:.72rem;font-weight:700;color:var(--p6);background:rgba(246,180,155,.45);padding:.25rem .6rem;border-radius:999px;white-space:nowrap}
    .pchy-act{border:0;cursor:pointer;width:34px;height:34px;border-radius:10px;display:inline-grid;place-items:center;transition:.18s;text-decoration:none}
    .pchy-edit{background:rgba(248,215,176,.7);color:var(--p5)}
    .pchy-edit:hover{background:var(--p4);color:#fff}
    .pchy-del{background:rgba(239,133,133,.18);color:var(--p5)}
    .pchy-del:hover{background:var(--p5);color:#fff}
    .pchy-empty{text-align:center;color:#b3a099;padding:2rem .5rem!important;font-size:.86rem}

    .pchy-tools{display:flex;align-items:center;justify-content:flex-end;gap:.45rem;flex-wrap:wrap}
    .pchy-search{max-width:190px;width:100%}
    .pchy-select{font-family:'Karla',sans-serif;font-size:.78rem;color:#4a3b36;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:999px;padding:.45rem .7rem;min-height:34px}
    .pchy-select:focus{outline:none;border-color:var(--p4);box-shadow:0 0 0 3px rgba(244,132,95,.12)}
    .pchy-pager{display:flex;align-items:center;justify-content:space-between;gap:.6rem;flex-wrap:wrap;margin-top:.75rem;padding-top:.7rem;border-top:1px solid rgba(246,180,155,.35);font-size:.74rem;color:#8d7a73}
    .pchy-pager-nav{display:flex;align-items:center;gap:.25rem}
    .pchy-page-btn{min-width:30px;height:30px;padding:0 .45rem;border:1px solid rgba(246,180,155,.55);border-radius:8px;background:#fff;color:#5b4740;font-family:'Karla',sans-serif;font-size:.75rem;font-weight:700;cursor:pointer}
    .pchy-page-btn:hover:not(:disabled){border-color:var(--p4);color:var(--p5)}
    .pchy-page-btn:disabled{opacity:.4;cursor:not-allowed}
    .pchy-page-btn.active{background:linear-gradient(135deg,var(--p4),var(--p3));color:#fff;border-color:transparent}
    .pchy-page-gap{padding:0 .15rem;color:#c4b6ae}
    .pchy-row-hidden{display:none!important}

    @media(max-width:820px){.pchy-form{grid-template-columns:1fr}.pchy-form .pchy-actions{display:grid;grid-template-columns:1fr 1fr}.pchy-btn{width:100%}}
    @media(max-width:640px){
        .pchy-title{font-size:1.35rem}
        .pchy-card{padding:1rem;border-radius:15px}
        .pchy-tbl,.pchy-tbl tbody,.pchy-tbl tr,.pchy-tbl td{display:block;width:100%}
        .pchy-tbl thead{display:none}
        .pchy-tbl tr{background:#fffaf6;border:1px solid rgba(246,180,155,.45);border-radius:14px;padding:.7rem .8rem;margin-bottom:.6rem}
        .pchy-tbl td{background:transparent!important;border:0!important;border-radius:0!important;padding:.25rem 0;display:flex;align-items:center;justify-content:space-between;gap:.75rem;text-align:right}
        .pchy-tbl td::before{content:attr(data-l);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:#9a837b}
    }
</style>

<div class="pchy">

    <div class="pchy-head">
        <div>
            <h1 class="pchy-title">{{ isset($subcategory) ? 'Edit Subcategory' : 'Sub Category Items' }}</h1>
            <p class="pchy-sub">{{ isset($subcategory) ? 'Update this subcategory and its parent category.' : 'Group menu items under Peachy categories.' }}</p>
        </div>
        <span class="pchy-chip">{{ isset($subcategories) ? count($subcategories) : 0 }} Subcategories</span>
        {{-- Always shown, even at zero — see menu-items.blade.php for why. --}}
        <a href="{{ route('admin.archived') }}" class="pchy-chip" style="text-decoration:none;">
            <i class="bi bi-archive"></i> {{ $archivedCount ?? 0 }} Archived
        </a>
    </div>

    @if ($errors->any())
    <div class="pchy-alert">
        @foreach ($errors->all() as $error)
        <div><i class="bi bi-exclamation-triangle-fill"></i> {{ $error }}</div>
        @endforeach
    </div>
    @endif

    {{-- Add / Edit Form --}}
    <div class="pchy-card">
        <div class="pchy-card-hd">
            <p class="pchy-card-t">
                <span class="pchy-ico"><i class="bi bi-{{ isset($subcategory) ? 'pencil-square' : 'plus-circle' }}"></i></span>
                {{ isset($subcategory) ? 'Edit Subcategory' : 'Add Subcategory' }}
            </p>
        </div>

        @if(isset($subcategory))
        {{-- EDIT MODE --}}
        <form action="{{ route('admin.add-subcategory.update', $subcategory->id) }}" method="POST" class="pchy-form">
            @csrf
            @method('PUT')
            <div>
                <label class="pchy-label">Parent Category</label>
                <select name="category_id" class="pchy-in" required>
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ $subcategory->category_id == $cat->id ? 'selected' : '' }}>
                        {{ $cat->name }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="pchy-label">Subcategory Name</label>
                <input type="text" name="name" class="pchy-in" value="{{ old('name', $subcategory->name) }}" required>
            </div>
            <div class="pchy-actions">
                <button type="submit" class="pchy-btn"><i class="bi bi-check2"></i> Update</button>
                <a href="{{ route('admin.add-subcategory') }}" class="pchy-btn ghost"><i class="bi bi-x-lg"></i> Cancel</a>
            </div>
        </form>
        @else
        {{-- ADD MODE --}}
        <form action="{{ route('admin.add-subcategory.post') }}" method="POST" class="pchy-form">
            @csrf
            <div>
                <label class="pchy-label">Parent Category</label>
                <select name="category_id" class="pchy-in" required>
                    <option value="">-- Select category --</option>
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>
                        {{ $cat->name }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="pchy-label">Subcategory Name</label>
                <input type="text" name="name" class="pchy-in" value="{{ old('name') }}" required autocomplete="off">
            </div>
            <div class="pchy-actions">
                <button type="submit" class="pchy-btn"><i class="bi bi-plus-circle"></i> Add Subcategory</button>
            </div>
        </form>
        @endif
    </div>

    {{-- Subcategories Table --}}
    <div class="pchy-card">
        <div class="pchy-card-hd">
            <p class="pchy-card-t"><span class="pchy-ico"><i class="bi bi-tags"></i></span> All Subcategories
                <span class="pchy-count">{{ isset($subcategories) ? count($subcategories) : 0 }}</span>
            </p>
            <div class="pchy-tools">
                <div class="pchy-search">
                    <i class="bi bi-search"></i>
                    <input type="text" aria-label="Search subcategories" id="searchInput" autocomplete="off">
                </div>
                <select id="categoryFilter" class="pchy-select">
                    <option value="">All categories</option>
                    @foreach($categories as $catFilter)
                        <option value="{{ strtolower($catFilter->name) }}">{{ $catFilter->name }}</option>
                    @endforeach
                </select>
                <select id="pageSize" class="pchy-select">
                    <option value="10">10 / page</option>
                    <option value="25" selected>25 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                </select>
            </div>
        </div>
        <div class="pchy-tblwrap">
            <table class="pchy-tbl" id="subcategoryTable">
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
                        <td data-l="Subcategory" class="pchy-name">{{ $sub->name }}</td>
                        <td data-l="Parent"><span class="pchy-badge">{{ $sub->category->name ?? 'N/A' }}</span></td>
                        <td data-l="Edit">
                            <a href="{{ route('admin.add-subcategory.edit', $sub->id) }}" class="pchy-act pchy-edit" title="Edit">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        </td>
                        <td data-l="Delete">
                            <form action="{{ route('admin.add-subcategory.delete', $sub->id) }}" method="POST" onsubmit="return confirm('Delete this subcategory?')" style="margin:0;">
                                @csrf @method('DELETE')
                                <button type="submit" class="pchy-act pchy-del" title="Delete">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                    @else
                    <tr>
                        <td colspan="4" class="pchy-empty">No subcategories yet. Add your first subcategory above!</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
        <div class="pchy-pager" id="subPager" hidden>
            <span id="pagerInfo"></span>
            <div class="pchy-pager-nav">
                <button type="button" class="pchy-page-btn" id="prevPage">‹</button>
                <span id="pageNumbers"></span>
                <button type="button" class="pchy-page-btn" id="nextPage">›</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
(function () {
    const state = { page: 1, size: 25, search: '', category: '' };

    function rows() {
        return Array.from(document.querySelectorAll('#subcategoryTable tbody tr'))
            .filter(row => !row.querySelector('.pchy-empty'));
    }

    function render() {
        const all = rows();

        const filtered = all.filter(row => {
            const text = row.innerText.toLowerCase();
            const parent = (row.querySelector('[data-l="Parent"]')?.innerText || '').toLowerCase();

            return (!state.search || text.includes(state.search.toLowerCase()))
                && (!state.category || parent === state.category.toLowerCase());
        });

        const pages = Math.max(1, Math.ceil(filtered.length / state.size));
        state.page = Math.min(Math.max(1, state.page), pages);

        const start = (state.page - 1) * state.size;
        const end = Math.min(start + state.size, filtered.length);

        all.forEach(row => row.classList.add('pchy-row-hidden'));
        filtered.slice(start, end).forEach(row => row.classList.remove('pchy-row-hidden'));

        const pager = document.getElementById('subPager');
        pager.hidden = filtered.length === 0 || pages === 1;

        document.getElementById('pagerInfo').textContent =
            filtered.length ? `Showing ${start + 1}–${end} of ${filtered.length}` : 'No matching results';

        document.getElementById('prevPage').disabled = state.page === 1;
        document.getElementById('nextPage').disabled = state.page === pages;

        const container = document.getElementById('pageNumbers');
        container.innerHTML = '';

        const nums = [];
        for (let i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - state.page) <= 1) nums.push(i);
            else if (nums[nums.length - 1] !== '…') nums.push('…');
        }

        nums.forEach(n => {
            if (n === '…') {
                const gap = document.createElement('span');
                gap.className = 'pchy-page-gap';
                gap.textContent = '…';
                container.appendChild(gap);
            } else {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pchy-page-btn' + (n === state.page ? ' active' : '');
                btn.textContent = n;
                btn.onclick = () => { state.page = n; render(); };
                container.appendChild(btn);
            }
        });
    }

    document.getElementById('searchInput')?.addEventListener('input', e => {
        state.search = e.target.value.trim();
        state.page = 1;
        render();
    });

    document.getElementById('categoryFilter')?.addEventListener('change', e => {
        state.category = e.target.value;
        state.page = 1;
        render();
    });

    document.getElementById('pageSize')?.addEventListener('change', e => {
        state.size = Number(e.target.value);
        state.page = 1;
        render();
    });

    document.getElementById('prevPage')?.addEventListener('click', () => {
        state.page--;
        render();
    });

    document.getElementById('nextPage')?.addEventListener('click', () => {
        state.page++;
        render();
    });

    render();
})();
</script>
@endpush

@extends('admin.layout')

@section('title', 'Categories - Peachy Admin')

@section('content')

<link href="/vendor/gfonts.css" rel="stylesheet">

<style>
    /*
     * THE INVISIBLE SAVE BUTTON, investigated 2026-09-02.
     *
     * --p1 through --p6 used to be defined only on .pchy, and every button's
     * gradient/colour reads them via var(). Both edit modals
     * (#editCategoryModal, #editSubcategoryModal) are DOM SIBLINGS of the
     * <div class="pchy"> wrapper, not descendants of it — confirmed by
     * reading the actual markup: .pchy opens, closes, and only THEN do the
     * modal divs begin. CSS custom properties only cascade to descendants of
     * the element that declares them, so inside either modal var(--p4) and
     * var(--p5) were simply undefined.
     *
     * .pchy-btn's `background: linear-gradient(135deg, var(--p4), var(--p5))`
     * with an unresolved var() is invalid at computed-value time, which
     * resets the WHOLE background property to its initial value —
     * transparent, revealing the white modal box behind it. `color:#fff` is
     * a plain static value, not a var(), so it was never invalidated and
     * rendered exactly as declared. White text on a transparent background
     * over a white modal is the "blank white button" that was reported —
     * the label, icon and styling were never actually missing, only
     * unrenderable from where the modals sit in the DOM.
     *
     * Fixed at the source rather than patching the two buttons: these tokens
     * now live on :root, which every element on the page can see regardless
     * of nesting, so any modal added to this page later inherits them
     * automatically instead of needing to remember to nest inside .pchy.
     */
    :root{--p1:#F8D7B0;--p2:#F6B49B;--p3:#EF8585;--p4:#F4845F;--p5:#C0392B;--p6:#8B1A1A}
    .pchy{font-family:'Karla',system-ui,sans-serif;color:#4a3b36}
    .pchy *{box-sizing:border-box}
    .pchy-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:.75rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:2px dashed rgba(244,132,95,.35)}
    .pchy-title{font-family:'Fraunces',Georgia,serif;font-size:1.7rem;font-weight:600;line-height:1.15;margin:0;color:var(--p6)}
    .pchy-sub{margin:.3rem 0 0;font-size:.85rem;color:#8d7a73}
    .pchy-chips{display:flex;gap:.45rem;flex-wrap:wrap}
    .pchy-chip{background:linear-gradient(135deg,var(--p1),var(--p2));color:var(--p6);font-size:.74rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:.4rem .7rem;border-radius:999px}
    .pchy-alert{background:#fff2f0;border:1px solid rgba(192,57,43,.28);border-left:5px solid var(--p5);color:var(--p6);padding:.75rem 1rem;border-radius:12px;font-size:.85rem;margin-bottom:1.1rem}
    .pchy-alert div+div{margin-top:.25rem}
    .pchy-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.1rem}
    .pchy-card{background:#fff;border:1px solid rgba(246,180,155,.45);border-radius:18px;padding:1.15rem;box-shadow:0 10px 26px -18px rgba(139,26,26,.35)}
    .pchy-card-hd{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.9rem}
    .pchy-card-t{font-family:'Fraunces',Georgia,serif;font-size:1.05rem;font-weight:600;color:var(--p6);margin:0;display:flex;align-items:center;gap:.5rem}
    .pchy-ico{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:linear-gradient(135deg,var(--p2),var(--p4));color:#fff;font-size:.85rem}
    .pchy-count{font-size:.72rem;font-weight:700;color:var(--p5);background:rgba(248,215,176,.55);padding:.25rem .55rem;border-radius:999px}
    .pchy-field{margin-bottom:.8rem}
    .pchy-label{display:block;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9a837b;margin-bottom:.35rem}
    .pchy-in{width:100%;font-family:'Karla',sans-serif;font-size:.92rem;color:#4a3b36;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:11px;padding:.65rem .8rem;transition:.18s}
    .pchy-in:focus{outline:none;border-color:var(--p4);background:#fff;box-shadow:0 0 0 3px rgba(244,132,95,.18)}
    .pchy-in[type=file]{padding:.5rem .6rem;font-size:.82rem}
    .pchy-btn{width:100%;font-family:'Karla',sans-serif;font-size:.9rem;font-weight:700;border:0;cursor:pointer;border-radius:11px;padding:.72rem 1rem;color:#fff;background:linear-gradient(135deg,var(--p4),var(--p5));box-shadow:0 8px 18px -10px rgba(192,57,43,.7);transition:.18s;display:inline-flex;align-items:center;justify-content:center;gap:.45rem}
    .pchy-btn:hover{filter:brightness(1.06);transform:translateY(-1px)}
    .pchy-search{position:relative;display:flex;align-items:center}
    .pchy-search i{position:absolute;left:.65rem;color:var(--p4);font-size:.8rem}
    .pchy-search input{width:100%;min-width:150px;font-family:'Karla',sans-serif;font-size:.84rem;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:999px;padding:.45rem .8rem .45rem 1.9rem}
    .pchy-search input:focus{outline:none;border-color:var(--p4)}
    .pchy-tblwrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .pchy-tbl{width:100%;border-collapse:separate;border-spacing:0 .4rem;font-size:.88rem}
    .pchy-tbl th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:#9a837b;text-align:left;padding:.35rem .7rem;font-weight:700;white-space:nowrap}
    .pchy-tbl td{background:#fffaf6;padding:.6rem .7rem;vertical-align:middle;border-top:1px solid rgba(246,180,155,.35);border-bottom:1px solid rgba(246,180,155,.35)}
    .pchy-tbl td:first-child{border-left:1px solid rgba(246,180,155,.35);border-radius:12px 0 0 12px}
    .pchy-tbl td:last-child{border-right:1px solid rgba(246,180,155,.35);border-radius:0 12px 12px 0;text-align:right}
    .pchy-tbl tbody tr:hover td{background:#fff4ec}
    .pchy-name{font-weight:600;color:#463430}
    .pchy-thumb{width:40px;height:40px;border-radius:10px;object-fit:cover;border:1.5px solid rgba(246,180,155,.7)}
    .pchy-thumb-ph{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;background:linear-gradient(135deg,#fdf1e6,var(--p1));color:var(--p4);font-size:.9rem}
    .pchy-badge{display:inline-block;font-size:.72rem;font-weight:700;color:var(--p6);background:rgba(248,215,176,.7);padding:.25rem .6rem;border-radius:999px;white-space:nowrap}
    .pchy-badge.alt{background:rgba(246,180,155,.45)}
    .pchy-del{border:0;cursor:pointer;background:rgba(239,133,133,.18);color:var(--p5);width:34px;height:34px;border-radius:10px;display:inline-grid;place-items:center;transition:.18s}
    .pchy-del:hover{background:var(--p5);color:#fff}
    .pchy-edit{border:0;cursor:pointer;background:rgba(248,215,176,.55);color:var(--p6);width:34px;height:34px;border-radius:10px;display:inline-grid;place-items:center;transition:.18s}
    .pchy-edit:hover{background:var(--p4);color:#fff}
    .pchy-empty{text-align:center;color:#b3a099;padding:2rem .5rem!important;font-size:.86rem}
    /* Action columns (Edit/Delete) — centered header + cell, independent of column position */
    .pchy-tbl th.pchy-col-action{text-align:center}
    .pchy-tbl td.pchy-col-action{text-align:center}

    .pchy-modal-overlay{display:none;position:fixed;inset:0;background:rgba(74,59,54,.5);z-index:1000;align-items:center;justify-content:center;padding:1rem}
    .pchy-modal-overlay.show{display:flex}
    .pchy-modal-box{background:#fff;border-radius:18px;padding:1.3rem;width:100%;max-width:400px;max-height:calc(100vh - 2rem);overflow-y:auto;box-shadow:0 20px 50px -20px rgba(139,26,26,.5)}
    .pchy-modal-hd{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1rem;padding-bottom:.85rem;border-bottom:2px dashed rgba(244,132,95,.3)}
    .pchy-modal-close{border:0;background:none;color:#9a837b;font-size:1.3rem;line-height:1;cursor:pointer;padding:0}
    .pchy-modal-close:hover{color:var(--p5)}
    .pchy-modal-preview-wrap{display:flex;flex-direction:column;align-items:center;gap:.5rem;margin-bottom:1.1rem;padding-bottom:1rem;border-bottom:1px dashed rgba(244,132,95,.3)}
    .pchy-modal-preview{width:130px;height:130px;object-fit:cover;border-radius:14px;border:1.5px solid rgba(246,180,155,.6);box-shadow:0 8px 20px -14px rgba(139,26,26,.4);display:block}
    .pchy-modal-preview-ph{width:130px;height:130px;border-radius:14px;background:linear-gradient(135deg,#fdf1e6,var(--p1));color:var(--p4);display:grid;place-items:center;font-size:1.8rem}
    .pchy-modal-foot{display:flex;gap:.6rem;margin-top:.3rem}
    .pchy-modal-foot .pchy-btn{width:auto;flex:1}
    .pchy-btn-ghost{background:#fff;color:var(--p5);box-shadow:none;border:1.5px solid rgba(246,180,155,.6)}
    .pchy-btn-ghost:hover{background:#fff4ec;filter:none;transform:none}

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

    @media(max-width:640px){
        .pchy-title{font-size:1.35rem}
        .pchy-card{padding:1rem;border-radius:15px}
        .pchy-tbl,.pchy-tbl tbody,.pchy-tbl tr,.pchy-tbl td{display:block;width:100%}
        .pchy-tbl thead{display:none}
        .pchy-tbl tr{background:#fffaf6;border:1px solid rgba(246,180,155,.45);border-radius:14px;padding:.7rem .8rem;margin-bottom:.6rem}
        .pchy-tbl td{background:transparent!important;border:0!important;border-radius:0!important;padding:.25rem 0;display:flex;align-items:center;justify-content:space-between;gap:.75rem;text-align:right!important}
        .pchy-tbl td::before{content:attr(data-l);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:#9a837b}
    }
</style>

<div class="pchy">

    <div class="pchy-head">
        <div>
            <h1 class="pchy-title">Categories &amp; Subcategories</h1>
            <p class="pchy-sub">Organise the Peachy Cakes &amp; Deli Cafe menu structure.</p>
        </div>
        <div class="pchy-chips">
            <span class="pchy-chip">{{ isset($categories) ? count($categories) : 0 }} Categories</span>
            <span class="pchy-chip">{{ isset($subcategories) ? count($subcategories) : 0 }} Subcategories</span>
            {{-- Always shown, even at zero — see menu-items.blade.php for why. --}}
            <a href="{{ route('admin.archived') }}" class="pchy-chip" style="text-decoration:none;">
                <i class="bi bi-archive"></i> {{ $archivedCount ?? 0 }} Archived
            </a>
        </div>
    </div>

    @if ($errors->any())
    <div class="pchy-alert">
        @foreach ($errors->all() as $error)
        <div><i class="bi bi-exclamation-triangle-fill"></i> {{ $error }}</div>
        @endforeach
    </div>
    @endif

    {{-- Add Forms --}}
    <div class="pchy-grid" style="margin-bottom:1.1rem;">

        <div class="pchy-card">
            <div class="pchy-card-hd">
                <p class="pchy-card-t"><span class="pchy-ico"><i class="bi bi-collection"></i></span> New Category</p>
            </div>
            <form action="{{ route('admin.add-category.post') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="pchy-field">
                    <label class="pchy-label">Category Name</label>
                    <input type="text" name="name" class="pchy-in" value="{{ old('name') }}" required autocomplete="off">
                </div>
                <div class="pchy-field">
                    <label class="pchy-label">Category Image</label>
                    <input type="file" name="image" class="pchy-in" accept="image/*">
                </div>
                <button type="submit" class="pchy-btn"><i class="bi bi-plus-circle"></i> Add Category</button>
            </form>
        </div>

        <div class="pchy-card">
            <div class="pchy-card-hd">
                <p class="pchy-card-t"><span class="pchy-ico"><i class="bi bi-diagram-3"></i></span> New Subcategory</p>
            </div>
            <form action="{{ route('admin.add-subcategory.post') }}" method="POST">
                @csrf
                <div class="pchy-field">
                    <label class="pchy-label">Parent Category</label>
                    <select name="category_id" class="pchy-in" required>
                        <option value=""> Select category </option>
                        @if(isset($categories))
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                        @endif
                    </select>
                </div>
                <div class="pchy-field">
                    <label class="pchy-label">Subcategory Name</label>
                    <input type="text" name="name" class="pchy-in" value="{{ old('name') }}" required autocomplete="off">
                </div>
                <button type="submit" class="pchy-btn"><i class="bi bi-plus-circle"></i> Add Subcategory</button>
            </form>
        </div>

    </div>

    {{-- Lists --}}
    <div class="pchy-grid">

        <div class="pchy-card">
            <div class="pchy-card-hd">
                <p class="pchy-card-t"><span class="pchy-ico"><i class="bi bi-list-ul"></i></span> Categories
                    <span class="pchy-count">{{ isset($categories) ? count($categories) : 0 }}</span>
                </p>
                <div class="pchy-tools">
                    <div class="pchy-search">
                        <i class="bi bi-search"></i>
                        <input type="text" aria-label="Search categories" id="searchCat" autocomplete="off">
                    </div>
                    <select id="catPageSize" class="pchy-select">
                        <option value="10">10 / page</option>
                        <option value="25" selected>25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                </div>
            </div>
            <div class="pchy-tblwrap">
                <table class="pchy-tbl" id="catTable">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Image</th>
                            <th>Items</th>
                            <th class="pchy-col-action">Edit</th>
                            <th class="pchy-col-action">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if(isset($categories) && count($categories) > 0)
                        @foreach($categories as $cat)
                        <tr>
                            <td data-l="Category" class="pchy-name">{{ $cat->name }}</td>
                            <td data-l="Image">
                                @if($cat->image)
                                <img src="{{ \App\Support\Img::url($cat->image) }}" alt="{{ $cat->name }}" class="pchy-thumb">
                                @else
                                <div class="pchy-thumb-ph"><i class="bi bi-image"></i></div>
                                @endif
                            </td>
                            <td data-l="Items"><span class="pchy-badge">{{ $cat->menuItems->count() }} items</span></td>
                            <td data-l="Edit" class="pchy-col-action">
                                <button type="button"
                                        class="pchy-edit"
                                        title="Edit"
                                        onclick="openEditCategoryModal({{ \Illuminate\Support\Js::from(route('admin.add-category.update', $cat->id)) }}, {{ \Illuminate\Support\Js::from($cat->name) }}, {{ \Illuminate\Support\Js::from($cat->image ? \App\Support\Img::url($cat->image) : null) }})">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            </td>
                            <td data-l="Delete" class="pchy-col-action">
                                <form action="{{ route('admin.add-category.delete', $cat->id) }}" method="POST" onsubmit="return confirm('Delete category {{ $cat->name }}?')" style="margin:0;">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="pchy-del" title="Delete"><i class="bi bi-trash3"></i></button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                        @else
                        <tr>
                            <td colspan="5" class="pchy-empty">No categories yet</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div class="pchy-pager" id="catPager" hidden>
                <span id="catPagerInfo"></span>
                <div class="pchy-pager-nav">
                    <button type="button" class="pchy-page-btn" id="catPrev">‹</button>
                    <span id="catPages"></span>
                    <button type="button" class="pchy-page-btn" id="catNext">›</button>
                </div>
            </div>
        </div>

        <div class="pchy-card">
            <div class="pchy-card-hd">
                <p class="pchy-card-t"><span class="pchy-ico"><i class="bi bi-tags"></i></span> Subcategories
                    <span class="pchy-count">{{ isset($subcategories) ? count($subcategories) : 0 }}</span>
                </p>
                <div class="pchy-tools">
                    <div class="pchy-search">
                        <i class="bi bi-search"></i>
                        <input type="text" aria-label="Search subcategories" id="searchSub" autocomplete="off">
                    </div>
                    <select id="subCategoryFilter" class="pchy-select">
                        <option value="">All categories</option>
                        @if(isset($categories))
                        @foreach($categories as $catFilter)
                            <option value="{{ strtolower($catFilter->name) }}">{{ $catFilter->name }}</option>
                        @endforeach
                        @endif
                    </select>
                    <select id="subPageSize" class="pchy-select">
                        <option value="10">10 / page</option>
                        <option value="25" selected>25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                </div>
            </div>
            <div class="pchy-tblwrap">
                <table class="pchy-tbl" id="subTable">
                    <thead>
                        <tr>
                            <th>Subcategory</th>
                            <th>Category</th>
                            <th class="pchy-col-action">Edit</th>
                            <th class="pchy-col-action">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if(isset($subcategories) && count($subcategories) > 0)
                        @foreach($subcategories as $sub)
                        <tr>
                            <td data-l="Subcategory" class="pchy-name">{{ $sub->name }}</td>
                            <td data-l="Category"><span class="pchy-badge alt">{{ $sub->category->name ?? 'N/A' }}</span></td>
                            <td data-l="Edit" class="pchy-col-action">
                                <button type="button"
                                        class="pchy-edit"
                                        title="Edit"
                                        onclick="openEditSubcategoryModal({{ \Illuminate\Support\Js::from(route('admin.add-subcategory.update', $sub->id)) }}, {{ \Illuminate\Support\Js::from($sub->name) }}, {{ \Illuminate\Support\Js::from($sub->category_id) }})">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            </td>
                            <td data-l="Delete" class="pchy-col-action">
                                <form action="{{ route('admin.add-subcategory.delete', $sub->id) }}" method="POST" onsubmit="return confirm('Delete subcategory {{ $sub->name }}?')" style="margin:0;">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="pchy-del" title="Delete"><i class="bi bi-trash3"></i></button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                        @else
                        <tr>
                            <td colspan="4" class="pchy-empty">No subcategories yet</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div class="pchy-pager" id="subPager" hidden>
                <span id="subPagerInfo"></span>
                <div class="pchy-pager-nav">
                    <button type="button" class="pchy-page-btn" id="subPrev">‹</button>
                    <span id="subPages"></span>
                    <button type="button" class="pchy-page-btn" id="subNext">›</button>
                </div>
            </div>
        </div>

    </div>
</div>

{{-- Edit Category Modal --}}
<div class="pchy-modal-overlay" id="editCategoryModal">
    <div class="pchy-modal-box">
        <div class="pchy-modal-hd">
            <p class="pchy-card-t" style="margin:0;"><span class="pchy-ico"><i class="bi bi-pencil-square"></i></span> Edit Category</p>
            <button type="button" class="pchy-modal-close" onclick="closeEditCategoryModal()">&times;</button>
        </div>

        <form id="editCategoryForm" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="pchy-modal-preview-wrap">
                <img id="editCategoryPreview" class="pchy-modal-preview" src="" alt="" style="display:none;">
                <div id="editCategoryPreviewPh" class="pchy-modal-preview-ph"><i class="bi bi-image"></i></div>
                <span class="pchy-label" style="margin:0;">Current Image</span>
            </div>

            <div class="pchy-field">
                <label class="pchy-label">Category Name</label>
                <input type="text" name="name" id="editCategoryName" class="pchy-in" required>
            </div>

            <div class="pchy-field" style="margin-bottom:0;">
                <label class="pchy-label">Replace Image (optional)</label>
                <input type="file" name="image" class="pchy-in" accept="image/*">
            </div>

            <div class="pchy-modal-foot">
                <button type="button" class="pchy-btn pchy-btn-ghost" onclick="closeEditCategoryModal()">Cancel</button>
                <button type="submit" class="pchy-btn"><i class="bi bi-check-circle"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

{{--
    Edit Subcategory Modal.

    Renaming and moving to a different parent category, in one form — the
    two fields updateSubcategory() actually accepts. Menu items stay
    attached to this subcategory no matter what changes here; only the
    subcategory's own row (and, when the parent changes, the category_id
    already filed under it — see updateSubcategory()'s docblock) is
    affected. This never touches subcategory_id on any item.
--}}
<div class="pchy-modal-overlay" id="editSubcategoryModal">
    <div class="pchy-modal-box">
        <div class="pchy-modal-hd">
            <p class="pchy-card-t" style="margin:0;"><span class="pchy-ico"><i class="bi bi-pencil-square"></i></span> Edit Subcategory</p>
            <button type="button" class="pchy-modal-close" onclick="closeEditSubcategoryModal()">&times;</button>
        </div>

        <form id="editSubcategoryForm" method="POST">
            @csrf
            @method('PUT')

            <div class="pchy-field">
                <label class="pchy-label">Parent Category</label>
                <select name="category_id" id="editSubcategoryCategory" class="pchy-in" required>
                    @if(isset($categories))
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                    @endif
                </select>
            </div>

            <div class="pchy-field" style="margin-bottom:0;">
                <label class="pchy-label">Subcategory Name</label>
                <input type="text" name="name" id="editSubcategoryName" class="pchy-in" required>
            </div>

            <div class="pchy-modal-foot">
                <button type="button" class="pchy-btn pchy-btn-ghost" onclick="closeEditSubcategoryModal()">Cancel</button>
                <button type="submit" class="pchy-btn"><i class="bi bi-check-circle"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
    function openEditCategoryModal(updateUrl, name, imageUrl) {
        document.getElementById('editCategoryForm').action = updateUrl;
        document.getElementById('editCategoryName').value = name;

        var preview = document.getElementById('editCategoryPreview');
        var placeholder = document.getElementById('editCategoryPreviewPh');
        if (imageUrl) {
            preview.src = imageUrl;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
        } else {
            preview.style.display = 'none';
            placeholder.style.display = 'grid';
        }

        document.getElementById('editCategoryModal').classList.add('show');
    }

    function closeEditCategoryModal() {
        document.getElementById('editCategoryModal').classList.remove('show');
    }

    function openEditSubcategoryModal(updateUrl, name, categoryId) {
        document.getElementById('editSubcategoryForm').action = updateUrl;
        document.getElementById('editSubcategoryName').value = name;
        document.getElementById('editSubcategoryCategory').value = categoryId;

        document.getElementById('editSubcategoryModal').classList.add('show');
    }

    function closeEditSubcategoryModal() {
        document.getElementById('editSubcategoryModal').classList.remove('show');
    }

(function () {
    const state = {
        cat: { page: 1, size: 25, search: '' },
        sub: { page: 1, size: 25, search: '', category: '' }
    };

    function getRows(tableId) {
        return Array.from(document.querySelectorAll('#' + tableId + ' tbody tr'))
            .filter(row => !row.querySelector('.pchy-empty'));
    }

    function render(type) {
        const isCat = type === 'cat';
        const tableId = isCat ? 'catTable' : 'subTable';
        const pagerId = isCat ? 'catPager' : 'subPager';
        const infoId = isCat ? 'catPagerInfo' : 'subPagerInfo';
        const pagesId = isCat ? 'catPages' : 'subPages';
        const prevId = isCat ? 'catPrev' : 'subPrev';
        const nextId = isCat ? 'catNext' : 'subNext';

        const rows = getRows(tableId);
        const s = state[type];

        const filtered = rows.filter(row => {
            const text = row.innerText.toLowerCase();
            if (s.search && !text.includes(s.search.toLowerCase())) return false;

            if (!isCat && s.category) {
                const parent = (row.querySelector('[data-l="Category"]')?.innerText || '').toLowerCase();
                if (parent !== s.category.toLowerCase()) return false;
            }
            return true;
        });

        const pages = Math.max(1, Math.ceil(filtered.length / s.size));
        s.page = Math.min(Math.max(1, s.page), pages);

        const start = (s.page - 1) * s.size;
        const end = Math.min(start + s.size, filtered.length);

        rows.forEach(row => row.classList.add('pchy-row-hidden'));
        filtered.slice(start, end).forEach(row => row.classList.remove('pchy-row-hidden'));

        const pager = document.getElementById(pagerId);
        pager.hidden = filtered.length === 0 || pages === 1;

        document.getElementById(infoId).textContent =
            filtered.length ? `Showing ${start + 1}–${end} of ${filtered.length}` : 'No matching results';

        document.getElementById(prevId).disabled = s.page === 1;
        document.getElementById(nextId).disabled = s.page === pages;

        const pagesEl = document.getElementById(pagesId);
        pagesEl.innerHTML = '';

        const nums = [];
        for (let i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - s.page) <= 1) nums.push(i);
            else if (nums[nums.length - 1] !== '…') nums.push('…');
        }

        nums.forEach(n => {
            if (n === '…') {
                const gap = document.createElement('span');
                gap.className = 'pchy-page-gap';
                gap.textContent = '…';
                pagesEl.appendChild(gap);
            } else {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pchy-page-btn' + (n === s.page ? ' active' : '');
                btn.textContent = n;
                btn.onclick = () => { s.page = n; render(type); };
                pagesEl.appendChild(btn);
            }
        });
    }

    document.getElementById('searchCat')?.addEventListener('input', e => {
        state.cat.search = e.target.value.trim();
        state.cat.page = 1;
        render('cat');
    });

    document.getElementById('searchSub')?.addEventListener('input', e => {
        state.sub.search = e.target.value.trim();
        state.sub.page = 1;
        render('sub');
    });

    document.getElementById('subCategoryFilter')?.addEventListener('change', e => {
        state.sub.category = e.target.value;
        state.sub.page = 1;
        render('sub');
    });

    document.getElementById('catPageSize')?.addEventListener('change', e => {
        state.cat.size = Number(e.target.value);
        state.cat.page = 1;
        render('cat');
    });

    document.getElementById('subPageSize')?.addEventListener('change', e => {
        state.sub.size = Number(e.target.value);
        state.sub.page = 1;
        render('sub');
    });

    document.getElementById('catPrev')?.addEventListener('click', () => {
        state.cat.page--;
        render('cat');
    });

    document.getElementById('catNext')?.addEventListener('click', () => {
        state.cat.page++;
        render('cat');
    });

    document.getElementById('subPrev')?.addEventListener('click', () => {
        state.sub.page--;
        render('sub');
    });

    document.getElementById('subNext')?.addEventListener('click', () => {
        state.sub.page++;
        render('sub');
    });

    render('cat');
    render('sub');
})();
</script>
@endpush

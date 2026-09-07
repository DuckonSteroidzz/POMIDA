@extends('admin.layout')

@section('title', 'Menu Options - Peachy Admin')

@section('content')

<link href="/vendor/gfonts.css" rel="stylesheet">

<style>
    .pchy{--p1:#F8D7B0;--p2:#F6B49B;--p3:#EF8585;--p4:#F4845F;--p5:#C0392B;--p6:#8B1A1A;font-family:'Karla',system-ui,sans-serif;color:#4a3b36}
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

    .pchy-btn{font-family:'Karla',sans-serif;font-size:.9rem;font-weight:700;border:0;cursor:pointer;border-radius:11px;padding:.72rem 1rem;color:#fff;background:linear-gradient(135deg,var(--p4),var(--p5));box-shadow:0 8px 18px -10px rgba(192,57,43,.7);transition:.18s;display:inline-flex;align-items:center;justify-content:center;gap:.45rem}
    .pchy-btn:hover{filter:brightness(1.06);transform:translateY(-1px)}
    .pchy-btn-full{width:100%}

    .pchy-tools{display:flex;align-items:center;justify-content:flex-end;gap:.45rem;flex-wrap:wrap}
    .pchy-search{position:relative;display:flex;align-items:center;max-width:210px;width:100%}
    .pchy-search i{position:absolute;left:.65rem;color:var(--p4);font-size:.8rem}
    .pchy-search input{width:100%;font-family:'Karla',sans-serif;font-size:.84rem;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:999px;padding:.45rem .8rem .45rem 1.9rem}
    .pchy-search input:focus{outline:none;border-color:var(--p4)}
    .pchy-select{font-family:'Karla',sans-serif;font-size:.78rem;color:#4a3b36;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:999px;padding:.45rem .7rem;min-height:34px}
    .pchy-select:focus{outline:none;border-color:var(--p4);box-shadow:0 0 0 3px rgba(244,132,95,.12)}

    .pchy-option-list{display:flex;flex-direction:column;gap:.5rem}
    .pchy-option{background:#fffaf6;border:1.5px solid rgba(246,180,155,.42);border-radius:13px;padding:.7rem .75rem;transition:.18s}
    .pchy-option:hover{border-color:rgba(244,132,95,.7);background:#fff4ec}
    .pchy-option-main{display:flex;align-items:center;justify-content:space-between;gap:.7rem}
    .pchy-option-left{display:flex;align-items:center;gap:.7rem;min-width:0}
    .pchy-option-check{width:17px;height:17px;accent-color:var(--p4);flex:0 0 auto}
    .pchy-option-name{font-size:.84rem;font-weight:700;color:#463430}
    .pchy-price{font-size:.72rem;color:var(--p4);font-weight:700;margin-left:.35rem}
    .pchy-free{font-size:.72rem;color:#aaa;margin-left:.35rem}
    .pchy-delete-btn{background:rgba(239,133,133,.14);color:var(--p5);border:1px solid rgba(192,57,43,.15);border-radius:8px;width:32px;height:30px;cursor:pointer}
    .pchy-delete-btn:hover{background:var(--p5);color:#fff}
    .pchy-edit-btn{background:rgba(244,132,95,.14);color:var(--p4);border:1px solid rgba(244,132,95,.22);border-radius:8px;width:32px;height:30px;cursor:pointer}
    .pchy-edit-btn:hover{background:var(--p4);color:#fff}
    /* Same collapsed/expand shape as .pchy-ing, so an option's edit panel and
       its ingredients panel read as the same kind of thing on this screen. */
    .pchy-edit{display:none;margin-top:.65rem;padding:.7rem;background:#fff;border:1px dashed rgba(244,132,95,.42);border-radius:11px}
    .pchy-edit.show{display:block}
    .pchy-edit-form{display:grid;grid-template-columns:minmax(0,1fr) 130px auto;gap:.5rem;align-items:end}
    @media (max-width:520px){ .pchy-edit-form{grid-template-columns:1fr} }

    /* "Add New Option" row — same collapse as .pchy-edit-form above. The
       180px name field + 150px price + auto button overflowed a phone. */
    .pchy-add-form{display:grid;grid-template-columns:minmax(0,1fr) 150px auto;gap:.7rem;align-items:end}
    @media (max-width:520px){ .pchy-add-form{grid-template-columns:1fr} }

    /* ── Recipe ingredients per option ── */
    .pchy-ing-toggle{display:inline-flex;align-items:center;gap:.3rem;background:rgba(248,215,176,.5);color:var(--p5);border:1px solid rgba(244,132,95,.28);border-radius:8px;height:30px;padding:0 .5rem;font-family:'Karla',sans-serif;font-size:.72rem;font-weight:700;cursor:pointer}
    .pchy-ing-toggle:hover{background:var(--p4);color:#fff;border-color:transparent}
    .pchy-ing-count{background:rgba(255,255,255,.65);color:var(--p6);border-radius:999px;padding:0 .32rem;font-size:.66rem}
    .pchy-ing-toggle:hover .pchy-ing-count{background:rgba(255,255,255,.25);color:#fff}

    .pchy-ing{display:none;margin-top:.65rem;padding:.7rem;background:#fff;border:1px dashed rgba(244,132,95,.42);border-radius:11px}
    .pchy-ing.show{display:block}
    .pchy-ing-hd{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin:0 0 .55rem;font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--p5)}
    .pchy-ing-note{text-transform:none;letter-spacing:0;font-weight:500;color:#9a837b;font-size:.7rem}
    .pchy-ing-list{display:flex;flex-direction:column;gap:.3rem;margin-bottom:.55rem}
    .pchy-ing-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:.5rem;background:#fffaf6;border:1px solid rgba(246,180,155,.4);border-radius:9px;padding:.4rem .55rem}
    .pchy-ing-name{font-size:.78rem;font-weight:600;color:#463430;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .pchy-ing-qty{font-size:.74rem;font-weight:700;color:var(--p4);white-space:nowrap}
    .pchy-ing-del{background:none;border:0;color:var(--p5);cursor:pointer;font-size:.7rem;padding:.15rem .25rem;border-radius:6px}
    .pchy-ing-del:hover{background:var(--p5);color:#fff}
    .pchy-ing-empty{font-size:.74rem;color:#b3a099;margin:0 0 .55rem}
    .pchy-ing-form{display:grid;grid-template-columns:minmax(0,1fr) 90px auto;gap:.4rem;align-items:center}
    .pchy-ing-select,.pchy-ing-qty-in{font-size:.78rem;padding:.45rem .55rem;margin:0}
    .pchy-ing-add{padding:.5rem .7rem;font-size:.78rem}
    @media(max-width:640px){
        .pchy-ing-form{grid-template-columns:1fr}
    }

    .pchy-menu-card{min-height:100%}
    .pchy-menu-hint{font-size:.75rem;color:#8d7a73;margin:-.35rem 0 .8rem}
    .pchy-category{margin-bottom:.8rem}
    .pchy-category-title{display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,var(--p5),#a52f25);color:#fff;padding:.55rem .75rem;border-radius:10px;font-size:.78rem;font-weight:700}
    .pchy-category-count{font-size:.68rem;background:rgba(255,255,255,.18);padding:.2rem .45rem;border-radius:999px}
    .pchy-menu-items{display:flex;flex-direction:column;gap:.35rem;margin-top:.4rem}
    .pchy-menu-item{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.55rem .7rem;background:#fffaf6;border:1.5px solid transparent;border-radius:10px;cursor:pointer;transition:.18s}
    .pchy-menu-item:hover{border-color:rgba(244,132,95,.5);transform:translateX(2px)}
    .pchy-menu-item.active{border-color:var(--p4);background:#fce0d0;box-shadow:0 5px 14px -10px rgba(192,57,43,.55)}
    .pchy-menu-item-name{font-size:.81rem;font-weight:600;color:#463430}
    .pchy-menu-item-count{font-size:.68rem;font-weight:700;color:var(--p4);white-space:nowrap}
    .pchy-no-items{padding:.45rem .7rem;font-size:.74rem;color:#aaa}

    .pchy-assign{display:none;margin-top:.85rem;padding:.75rem;background:linear-gradient(135deg,#fff4ec,#fffaf6);border:1px solid rgba(244,132,95,.3);border-radius:12px}
    .pchy-assign.show{display:block}
    .pchy-selected-label{font-size:.75rem;color:#8d7a73;margin-bottom:.5rem}
    .pchy-selected-name{font-weight:700;color:var(--p5)}

    .pchy-pager{display:flex;align-items:center;justify-content:space-between;gap:.6rem;flex-wrap:wrap;margin-top:.8rem;padding-top:.75rem;border-top:1px solid rgba(246,180,155,.35);font-size:.74rem;color:#8d7a73}
    .pchy-page-nav{display:flex;align-items:center;gap:.25rem}
    .pchy-page-btn{min-width:31px;height:31px;padding:0 .45rem;border:1px solid rgba(246,180,155,.55);border-radius:8px;background:#fff;color:#5b4740;font-family:'Karla',sans-serif;font-size:.75rem;font-weight:700;cursor:pointer}
    .pchy-page-btn:hover:not(:disabled){border-color:var(--p4);color:var(--p5)}
    .pchy-page-btn:disabled{opacity:.4;cursor:not-allowed}
    .pchy-page-btn.active{background:linear-gradient(135deg,var(--p4),var(--p3));color:#fff;border-color:transparent}
    .pchy-page-gap{padding:0 .15rem;color:#c4b6ae}
    .pchy-row-hidden{display:none!important}
    .pchy-empty{text-align:center;color:#b3a099;padding:2rem .5rem;font-size:.84rem}
    .pchy-empty i{display:block;font-size:2rem;color:#eadbd3;margin-bottom:.45rem}

    @media(max-width:900px){
        .pchy-grid{grid-template-columns:1fr}
    }

    @media(max-width:640px){
        .pchy-title{font-size:1.35rem}
        .pchy-card{padding:1rem;border-radius:15px}
        .pchy-tools{justify-content:stretch}
        .pchy-search{max-width:none}
        .pchy-select{flex:1;min-width:110px}
    }
</style>

<div class="pchy">

    <div class="pchy-head">
        <div>
            <h1 class="pchy-title">Menu Options</h1>
            <p class="pchy-sub">Manage add-ons and assign them to menu items.</p>
        </div>

        <div class="pchy-chips">
            <span class="pchy-chip">{{ count($options) }} Options</span>
            <span class="pchy-chip">{{ count($categories) }} Categories</span>
            {{-- Archived add-ons live off this list on purpose; this is how you get
                 back to them. Always shown, even at zero — see menu-items.blade.php
                 for why the entry point cannot be conditional. --}}
            <a href="{{ route('admin.archived') }}" class="pchy-chip" style="text-decoration:none;">
                <i class="bi bi-archive"></i> {{ $archivedCount ?? 0 }} Archived
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="pchy-alert">
            @foreach ($errors->all() as $error)
                <div>
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    {{ $error }}
                </div>
            @endforeach
        </div>
    @endif

    {{-- ADD OPTION --}}
    <div class="pchy-card" style="margin-bottom:1.1rem;">
        <div class="pchy-card-hd">
            <p class="pchy-card-t">
                <span class="pchy-ico"><i class="bi bi-plus-circle"></i></span>
                Add New Option
            </p>
        </div>

        <form action="{{ route('admin.menu-options.post') }}" method="POST">
            @csrf

            <div class="pchy-add-form">

                <div class="pchy-field" style="margin:0;">
                    <label class="pchy-label">Option Name *</label>
                    <input type="text"
                           name="name"
                           class="pchy-in"
                           required
                           autocomplete="off">
                </div>

                <div class="pchy-field" style="margin:0;">
                    <label class="pchy-label">Price (₱)</label>
                    <input type="number"
                           name="price"
                           class="pchy-in"
                           step="0.01"
                           min="0"
                           value="0">
                </div>

                <button type="submit" class="pchy-btn">
                    <i class="bi bi-plus-lg"></i>
                    Add Option
                </button>

            </div>
        </form>
    </div>

    <div class="pchy-grid">

        {{-- OPTIONS --}}
        <div class="pchy-card">

            <div class="pchy-card-hd">

                <p class="pchy-card-t">
                    <span class="pchy-ico">
                        <i class="bi bi-list-check"></i>
                    </span>
                    Options
                    <span class="pchy-count">{{ count($options) }}</span>
                </p>

                <div class="pchy-tools">

                    <div class="pchy-search">
                        <i class="bi bi-search"></i>
                        <input type="text"
                               id="optionSearch"
                               aria-label="Search options"
                               autocomplete="off">
                    </div>

                    <select id="optionPriceFilter" class="pchy-select">
                        <option value="">All prices</option>
                        <option value="free">Free</option>
                        <option value="paid">Paid</option>
                    </select>

                    <select id="optionPageSize" class="pchy-select">
                        <option value="10">10 / page</option>
                        <option value="25" selected>25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>

                </div>
            </div>

            <p style="font-size:.74rem;color:#8d7a73;margin:-.35rem 0 .8rem;">
                Select options, then click a menu item on the right to assign them.
            </p>

            <div id="optionsList" class="pchy-option-list">

                @if(count($options) > 0)

                    @foreach($options as $option)

                        <div class="pchy-option option-row"
                             id="opt-{{ $option->id }}"
                             data-name="{{ strtolower($option->name) }}"
                             data-price="{{ $option->additional_price > 0 ? 'paid' : 'free' }}">

                            <div class="pchy-option-main">

                                <div class="pchy-option-left">

                                    <input type="checkbox"
                                           class="option-check pchy-option-check"
                                           data-id="{{ $option->id }}">

                                    <div style="min-width:0;">

                                        <span class="pchy-option-name">
                                            {{ $option->name }}
                                        </span>

                                        @if($option->additional_price > 0)

                                            <span class="pchy-price">
                                                +₱{{ number_format($option->additional_price, 2) }}
                                            </span>

                                        @else

                                            <span class="pchy-free">
                                                Free
                                            </span>

                                        @endif

                                    </div>

                                </div>

                                <div style="display:flex;align-items:center;gap:.35rem;">

                                    <button type="button"
                                            class="pchy-ing-toggle"
                                            onclick="toggleIngredients({{ $option->id }})"
                                            title="Recipe ingredients">
                                        <i class="bi bi-basket"></i>
                                        <span class="pchy-ing-count">{{ $option->ingredients->count() }}</span>
                                    </button>

                                    <button type="button"
                                            class="pchy-edit-btn"
                                            onclick="toggleOptionEdit({{ $option->id }})"
                                            title="Edit option">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <form action="{{ route('admin.menu-options.delete', $option->id) }}"
                                          method="POST"
                                          onsubmit="return confirm('Delete this option?')"
                                          style="margin:0;">

                                        @csrf
                                        @method('DELETE')

                                        <button type="submit"
                                                class="pchy-delete-btn"
                                                title="Delete option">
                                            <i class="bi bi-trash3"></i>
                                        </button>

                                    </form>

                                </div>

                            </div>

                            {{--
                                EDIT NAME/PRICE ONLY.

                                A real form POSTing (PUT) to admin.menu-options.update,
                                not a fetch()-driven panel like the ingredients one below
                                — there is nothing here that benefits from staying open
                                across multiple saves, so a normal submit-and-redirect is
                                the simpler, harder-to-break choice.

                                Deliberately does NOT touch the pchy-option's data-name/
                                data-price attributes or assignments — those live on the
                                MenuOption row itself and this form's fields (name, price)
                                map to exactly the two columns updateMenuOption() writes,
                                nothing else. menu_item_options is never referenced here at
                                all, so there is nothing in this markup that could touch it.
                            --}}
                            <div class="pchy-edit" id="edit-{{ $option->id }}">

                                <form action="{{ route('admin.menu-options.update', $option->id) }}"
                                      method="POST"
                                      class="pchy-edit-form">

                                    @csrf
                                    @method('PUT')

                                    <div class="pchy-field" style="margin:0;">
                                        <label class="pchy-label">Option Name *</label>
                                        <input type="text"
                                               name="name"
                                               class="pchy-in"
                                               value="{{ $option->name }}"
                                               required>
                                    </div>

                                    <div class="pchy-field" style="margin:0;">
                                        <label class="pchy-label">Price (₱)</label>
                                        <input type="number"
                                               name="price"
                                               class="pchy-in"
                                               value="{{ $option->additional_price }}"
                                               step="0.01"
                                               min="0">
                                    </div>

                                    <button type="submit" class="pchy-btn">
                                        <i class="bi bi-check-lg"></i>
                                        Save
                                    </button>

                                </form>

                            </div>

                            {{-- RECIPE INGREDIENTS (MenuOptionIngredient) --}}
                            <div class="pchy-ing" id="ing-{{ $option->id }}">

                                <p class="pchy-ing-hd">
                                    <i class="bi bi-basket"></i>
                                    Recipe Ingredients
                                    <span class="pchy-ing-note">deducted only when this add-on is selected</span>
                                </p>

                                <p class="pchy-ing-empty" id="opt-ing-empty-{{ $option->id }}" @if($option->ingredients->count() > 0) style="display:none;" @endif>
                                    No ingredients linked. This add-on will not deduct any stock.
                                </p>

                                <div class="pchy-ing-list" id="opt-ing-list-{{ $option->id }}" @if($option->ingredients->count() === 0) style="display:none;" @endif>

                                    @foreach($option->ingredients as $ing)

                                        <div class="pchy-ing-row" data-ingredient-id="{{ $ing->id }}">

                                            <span class="pchy-ing-name">
                                                {{ $ing->inventory->item_name ?? 'Deleted item' }}
                                            </span>

                                            <span class="pchy-ing-qty">
                                                {{ rtrim(rtrim(number_format($ing->quantity_used, 3), '0'), '.') }}
                                                {{ $ing->inventory->unit ?? '' }}
                                            </span>

                                            <button type="button"
                                                    class="pchy-ing-del opt-ing-delete-btn"
                                                    data-url="{{ route('admin.menu-options.ingredients.delete', [$option->id, $ing->id]) }}"
                                                    title="Remove ingredient">
                                                <i class="bi bi-x-lg"></i>
                                            </button>

                                        </div>

                                    @endforeach

                                </div>

                                <p class="pchy-ing-error" id="opt-ing-error-{{ $option->id }}" style="display:none;color:#C0392B;background:#fdecea;border-radius:8px;padding:.4rem .6rem;font-size:.72rem;margin:0 0 .5rem;"></p>

                                <form class="pchy-ing-form opt-ing-add-form"
                                      data-url="{{ route('admin.menu-options.ingredients.add', $option->id) }}"
                                      data-option="{{ $option->id }}">

                                    @csrf

                                    <select name="inventory_id" class="pchy-in pchy-ing-select" required>
                                        <option value="">-- Select inventory item --</option>
                                        @foreach($inventoryItems as $inv)
                                            <option value="{{ $inv->id }}" data-unit="{{ $inv->unit }}">
                                                {{ $inv->item_name }} ({{ $inv->unit }})
                                                @if($inv->branch) — {{ $inv->branch->name }} @endif
                                            </option>
                                        @endforeach
                                    </select>

                                    <input type="number"
                                           name="quantity_used"
                                           class="pchy-in pchy-ing-qty-in"
                                           aria-label="Quantity used"
                                           step="0.001"
                                           min="0.001"
                                           required>

                                    <button type="submit" class="pchy-btn pchy-ing-add">
                                        <i class="bi bi-plus-lg"></i>
                                        Add
                                    </button>

                                </form>

                            </div>

                        </div>

                    @endforeach

                @else

                    <div class="pchy-empty">
                        <i class="bi bi-list-check"></i>
                        No options yet. Add one above.
                    </div>

                @endif

            </div>

            <div class="pchy-pager" id="optionPager" hidden>

                <span id="optionPagerInfo"></span>

                <div class="pchy-page-nav">
                    <button type="button"
                            class="pchy-page-btn"
                            id="optionPrev">
                        ‹
                    </button>

                    <span id="optionPages"></span>

                    <button type="button"
                            class="pchy-page-btn"
                            id="optionNext">
                        ›
                    </button>
                </div>

            </div>

            <div id="assignSection" class="pchy-assign">

                <div class="pchy-selected-label">
                    Assign selected options to:
                    <span id="selectedItemName"
                          class="pchy-selected-name">
                        Item
                    </span>
                </div>

                <button type="button"
                        onclick="saveAssignment()"
                        class="pchy-btn pchy-btn-full">

                    <i class="bi bi-check-circle"></i>
                    Save Options

                </button>

            </div>

        </div>


        {{-- MENU ITEMS --}}
        <div class="pchy-card pchy-menu-card">

            <div class="pchy-card-hd">

                <p class="pchy-card-t">
                    <span class="pchy-ico">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </span>
                    Menu Items
                </p>

                <div class="pchy-tools">

                    <div class="pchy-search">
                        <i class="bi bi-search"></i>
                        <input type="text"
                               id="menuSearch"
                               aria-label="Search menu items"
                               autocomplete="off">
                    </div>

                    <select id="menuCategoryFilter" class="pchy-select">

                        <option value="">
                            All categories
                        </option>

                        @foreach($categories as $catFilter)

                            <option value="{{ strtolower($catFilter->name) }}">
                                {{ $catFilter->name }}
                            </option>

                        @endforeach

                    </select>

                    <select id="menuPageSize" class="pchy-select">
                        <option value="10">10 / page</option>
                        <option value="25" selected>25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>

                </div>

            </div>

            <p class="pchy-menu-hint">
                Click a menu item to load its assigned options.
            </p>


            <div id="menuItemsList">

                @if(count($categories) > 0)

                    @foreach($categories as $category)

                        @if($category->menuItems->count() > 0)

                            @foreach($category->menuItems as $item)

                                <div class="menu-item-row menu-filter-row"
                                     data-id="{{ $item->id }}"
                                     data-name="{{ strtolower($item->name) }}"
                                     data-category="{{ strtolower($category->name) }}"
                                     data-options="{{ $item->options->pluck('id')->toJson() }}"
                                     onclick="selectMenuItem(this)">

                                    <div class="pchy-menu-item">

                                        <span class="pchy-menu-item-name">
                                            {{ $item->name }}
                                        </span>

                                        <span class="pchy-menu-item-count">
                                            {{ $item->options->count() }} option(s)
                                        </span>

                                    </div>

                                </div>

                            @endforeach

                        @endif

                    @endforeach

                @else

                    <div class="pchy-empty">
                        <i class="bi bi-grid-3x3-gap"></i>
                        No categories or menu items yet.
                    </div>

                @endif

            </div>


            <div class="pchy-pager" id="menuPager" hidden>

                <span id="menuPagerInfo"></span>

                <div class="pchy-page-nav">

                    <button type="button"
                            class="pchy-page-btn"
                            id="menuPrev">
                        ‹
                    </button>

                    <span id="menuPages"></span>

                    <button type="button"
                            class="pchy-page-btn"
                            id="menuNext">
                        ›
                    </button>

                </div>

            </div>

        </div>

    </div>

</div>

@endsection


@push('scripts')

<script>
(function () {

    const state = {

        option: {
            page: 1,
            size: 25,
            search: '',
            price: ''
        },

        menu: {
            page: 1,
            size: 25,
            search: '',
            category: ''
        }

    };


    let selectedItemId = null;


    function getOptionRows() {

        return Array.from(
            document.querySelectorAll('.option-row')
        );

    }


    function getMenuRows() {

        return Array.from(
            document.querySelectorAll('.menu-filter-row')
        );

    }


    function buildPages(totalPages, currentPage) {

        const pages = [];

        for (let i = 1; i <= totalPages; i++) {

            if (
                i === 1 ||
                i === totalPages ||
                Math.abs(i - currentPage) <= 1
            ) {

                pages.push(i);

            } else if (
                pages[pages.length - 1] !== '…'
            ) {

                pages.push('…');

            }

        }

        return pages;

    }


    function renderPager(
        type,
        filteredLength,
        start,
        end,
        totalPages
    ) {

        const isOption = type === 'option';

        const pager = document.getElementById(
            isOption ? 'optionPager' : 'menuPager'
        );

        const info = document.getElementById(
            isOption ? 'optionPagerInfo' : 'menuPagerInfo'
        );

        const pagesEl = document.getElementById(
            isOption ? 'optionPages' : 'menuPages'
        );

        const prev = document.getElementById(
            isOption ? 'optionPrev' : 'menuPrev'
        );

        const next = document.getElementById(
            isOption ? 'optionNext' : 'menuNext'
        );

        const s = state[type];

        pager.hidden =
            filteredLength === 0 ||
            totalPages <= 1;

        info.textContent = filteredLength
            ? `Showing ${start + 1}–${end} of ${filteredLength}`
            : 'No matching results';

        prev.disabled = s.page === 1;
        next.disabled = s.page === totalPages;

        pagesEl.innerHTML = '';


        buildPages(totalPages, s.page).forEach(n => {

            if (n === '…') {

                const gap =
                    document.createElement('span');

                gap.className = 'pchy-page-gap';
                gap.textContent = '…';

                pagesEl.appendChild(gap);

                return;

            }


            const btn =
                document.createElement('button');

            btn.type = 'button';

            btn.className =
                'pchy-page-btn' +
                (n === s.page ? ' active' : '');

            btn.textContent = n;

            btn.onclick = () => {

                s.page = n;

                render(type);

            };

            pagesEl.appendChild(btn);

        });

    }


    function render(type) {

        const isOption =
            type === 'option';

        const rows =
            isOption
                ? getOptionRows()
                : getMenuRows();

        const s =
            state[type];


        const filtered =
            rows.filter(row => {

                if (isOption) {

                    const name =
                        row.dataset.name || '';

                    const price =
                        row.dataset.price || '';


                    if (
                        s.search &&
                        !name.includes(
                            s.search.toLowerCase()
                        )
                    ) {
                        return false;
                    }


                    if (
                        s.price &&
                        price !== s.price
                    ) {
                        return false;
                    }


                    return true;

                }


                const name =
                    row.dataset.name || '';

                const category =
                    row.dataset.category || '';


                if (
                    s.search &&
                    !name.includes(
                        s.search.toLowerCase()
                    )
                ) {
                    return false;
                }


                if (
                    s.category &&
                    category !==
                    s.category.toLowerCase()
                ) {
                    return false;
                }


                return true;

            });


        const pages =
            Math.max(
                1,
                Math.ceil(
                    filtered.length /
                    s.size
                )
            );


        s.page =
            Math.min(
                Math.max(1, s.page),
                pages
            );


        const start =
            (s.page - 1) *
            s.size;


        const end =
            Math.min(
                start + s.size,
                filtered.length
            );


        rows.forEach(row => {

            row.classList.add(
                'pchy-row-hidden'
            );

        });


        filtered
            .slice(start, end)
            .forEach(row => {

                row.classList.remove(
                    'pchy-row-hidden'
                );

            });


        renderPager(
            type,
            filtered.length,
            start,
            end,
            pages
        );

    }


    window.toggleIngredients =
        function (optionId) {

            const panel =
                document.getElementById(
                    'ing-' + optionId
                );

            if (panel) {
                panel.classList.toggle('show');
            }

        };

    window.toggleOptionEdit =
        function (optionId) {

            const panel =
                document.getElementById(
                    'edit-' + optionId
                );

            if (panel) {
                panel.classList.toggle('show');
            }

        };


    // Add/remove option Recipe Ingredients via fetch() so the panel never
    // collapses while the admin is adding several ingredients in a row —
    // it only closes when they click the basket toggle button themselves.
    function optIngError(optionId, message) {
        const box = document.getElementById('opt-ing-error-' + optionId);
        if (!box) return;
        box.textContent = message;
        box.style.display = 'block';
    }

    function optIngClearError(optionId) {
        const box = document.getElementById('opt-ing-error-' + optionId);
        if (box) box.style.display = 'none';
    }

    document.addEventListener('submit', function (e) {
        if (!e.target.classList || !e.target.classList.contains('opt-ing-add-form')) return;
        e.preventDefault();

        const form = e.target;
        const optionId = form.dataset.option;
        optIngClearError(optionId);

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        fetch(form.dataset.url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
            },
            body: new FormData(form),
        })
            .then(res => res.json().then(data => ({ status: res.status, data })))
            .then(({ status, data }) => {
                if (submitBtn) submitBtn.disabled = false;

                if (status >= 400 || !data.success) {
                    const message = data.message
                        || (data.errors && Object.values(data.errors)[0][0])
                        || 'Could not add ingredient.';
                    optIngError(optionId, message);
                    return;
                }

                const ing = data.ingredient;

                const row = document.createElement('div');
                row.className = 'pchy-ing-row';
                row.dataset.ingredientId = ing.id;
                row.innerHTML =
                    '<span class="pchy-ing-name"></span>' +
                    '<span class="pchy-ing-qty"></span>' +
                    '<button type="button" class="pchy-ing-del opt-ing-delete-btn" data-url="' + ing.delete_url + '" title="Remove ingredient">' +
                    '<i class="bi bi-x-lg"></i></button>';
                row.querySelector('.pchy-ing-name').textContent = ing.name;
                row.querySelector('.pchy-ing-qty').textContent = ing.quantity_used + (ing.unit ? ' ' + ing.unit : '');

                document.getElementById('opt-ing-list-' + optionId).appendChild(row);
                document.getElementById('opt-ing-list-' + optionId).style.display = '';
                document.getElementById('opt-ing-empty-' + optionId).style.display = 'none';

                const countEl = form.closest('.pchy-option').querySelector('.pchy-ing-count');
                if (countEl) countEl.textContent = String(Number(countEl.textContent) + 1);

                form.querySelector('.pchy-ing-select').value = '';
                form.querySelector('input[name="quantity_used"]').value = '';
            })
            .catch(() => {
                if (submitBtn) submitBtn.disabled = false;
                optIngError(optionId, 'Network error — could not add ingredient.');
            });
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest ? e.target.closest('.opt-ing-delete-btn') : null;
        if (!btn) return;
        if (!confirm('Remove this ingredient?')) return;

        const row = btn.closest('.pchy-ing-row');
        const panel = btn.closest('.pchy-ing');
        const optionId = panel ? panel.id.replace('ing-', '') : null;
        const tokenInput = panel ? panel.querySelector('input[name="_token"]') : null;

        const fd = new FormData();
        fd.append('_method', 'DELETE');

        fetch(btn.dataset.url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenInput ? tokenInput.value : '',
            },
            body: fd,
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;
                if (row) row.remove();

                if (optionId) {
                    const list = document.getElementById('opt-ing-list-' + optionId);
                    if (list && list.children.length === 0) {
                        list.style.display = 'none';
                        document.getElementById('opt-ing-empty-' + optionId).style.display = 'block';
                    }

                    const optionRow = document.getElementById('opt-' + optionId);
                    const countEl = optionRow ? optionRow.querySelector('.pchy-ing-count') : null;
                    if (countEl) countEl.textContent = String(Math.max(0, Number(countEl.textContent) - 1));
                }
            });
    });


    window.selectMenuItem =
        function (el) {

            document
                .querySelectorAll(
                    '.menu-item-row'
                )
                .forEach(row => {

                    row.classList.remove(
                        'active'
                    );

                    const card =
                        row.querySelector(
                            '.pchy-menu-item'
                        );

                    if (card) {
                        card.classList.remove(
                            'active'
                        );
                    }

                });


            el.classList.add('active');


            const itemCard =
                el.querySelector(
                    '.pchy-menu-item'
                );

            if (itemCard) {
                itemCard.classList.add(
                    'active'
                );
            }


            selectedItemId =
                el.dataset.id;


            const assignedOptions =
                JSON.parse(
                    el.dataset.options || '[]'
                );


            const itemNameEl =
                el.querySelector(
                    '.pchy-menu-item-name'
                );


            document.getElementById(
                'selectedItemName'
            ).textContent =
                itemNameEl
                    ? itemNameEl.textContent.trim()
                    : 'Item';


            document.getElementById(
                'assignSection'
            ).classList.add('show');


            document
                .querySelectorAll(
                    '.option-check'
                )
                .forEach(cb => {

                    cb.checked =
                        assignedOptions.includes(
                            parseInt(
                                cb.dataset.id
                            )
                        );

                });

        };


    window.saveAssignment =
        function () {

            if (!selectedItemId) {
                return;
            }


            const checkedOptions = [];


            document
                .querySelectorAll(
                    '.option-check:checked'
                )
                .forEach(cb => {

                    checkedOptions.push(
                        cb.dataset.id
                    );

                });


            fetch(
                '{{ url('admin/menu-options/assign') }}/' +
                selectedItemId,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'X-CSRF-TOKEN':
                            '{{ csrf_token() }}'
                    },

                    body: JSON.stringify({
                        option_ids:
                            checkedOptions
                    })

                }
            )
            .then(res => res.json())
            .then(data => {

                if (!data.success) {

                    /*
                     * This used to be a bare `return` — the save failed and
                     * the admin was told nothing at all, so a rejected save
                     * looked identical to one that simply had not finished.
                     * Now it reports the server's reason the same way the
                     * success path reports success: on the button itself,
                     * flashed in the failure colour, then restored.
                     */
                    showAssignError(
                        data.message ||
                        'Could not save the options. Please refresh and try again.'
                    );

                    return;
                }


                const itemRow =
                    document.querySelector(
                        '.menu-item-row[data-id="' +
                        selectedItemId +
                        '"]'
                    );


                if (itemRow) {

                    const countEl =
                        itemRow.querySelector(
                            '.pchy-menu-item-count'
                        );


                    if (countEl) {

                        countEl.textContent =
                            checkedOptions.length +
                            ' option(s)';

                    }


                    itemRow.dataset.options =
                        JSON.stringify(
                            checkedOptions.map(Number)
                        );

                }


                const btn =
                    document.querySelector(
                        '#assignSection button'
                    );


                btn.innerHTML =
                    '<i class="bi bi-check2"></i> Saved!';

                btn.style.background =
                    '#4CAF50';


                setTimeout(() => {

                    btn.innerHTML =
                        '<i class="bi bi-check-circle"></i> Save Options';

                    btn.style.background = '';

                }, 2000);

            })
            .catch(err => {

                console.error(err);

                // A network/parse failure left the admin with no feedback at
                // all either — same silent-failure problem as a rejected
                // save, so it gets the same visible treatment.
                showAssignError(
                    'Could not reach the server. Your options were not saved.'
                );

            });

        };


    /**
     * Report a failed save on the Save Options button itself.
     *
     * Mirrors the success path's own feedback (green "Saved!", restored after
     * a moment) so success and failure are reported in the same place and the
     * same way — the admin is already looking at that button, having just
     * clicked it. Held longer than the success flash because a failure asks
     * the admin to actually do something about it.
     */
    function showAssignError(message) {

        const btn =
            document.querySelector(
                '#assignSection button'
            );

        if (!btn) {
            return;
        }

        const original =
            '<i class="bi bi-check-circle"></i> Save Options';

        btn.innerHTML =
            '<i class="bi bi-exclamation-triangle"></i> ' +
            message;

        btn.style.background = '#C0392B';

        setTimeout(() => {

            btn.innerHTML = original;

            btn.style.background = '';

        }, 6000);

    }


    document
        .getElementById('optionSearch')
        ?.addEventListener(
            'input',
            e => {

                state.option.search =
                    e.target.value.trim();

                state.option.page = 1;

                render('option');

            }
        );


    document
        .getElementById('optionPriceFilter')
        ?.addEventListener(
            'change',
            e => {

                state.option.price =
                    e.target.value;

                state.option.page = 1;

                render('option');

            }
        );


    document
        .getElementById('optionPageSize')
        ?.addEventListener(
            'change',
            e => {

                state.option.size =
                    Number(e.target.value);

                state.option.page = 1;

                render('option');

            }
        );


    document
        .getElementById('menuSearch')
        ?.addEventListener(
            'input',
            e => {

                state.menu.search =
                    e.target.value.trim();

                state.menu.page = 1;

                render('menu');

            }
        );


    document
        .getElementById('menuCategoryFilter')
        ?.addEventListener(
            'change',
            e => {

                state.menu.category =
                    e.target.value;

                state.menu.page = 1;

                render('menu');

            }
        );


    document
        .getElementById('menuPageSize')
        ?.addEventListener(
            'change',
            e => {

                state.menu.size =
                    Number(e.target.value);

                state.menu.page = 1;

                render('menu');

            }
        );


    document
        .getElementById('optionPrev')
        ?.addEventListener(
            'click',
            () => {

                if (state.option.page > 1) {

                    state.option.page--;

                    render('option');

                }

            }
        );


    document
        .getElementById('optionNext')
        ?.addEventListener(
            'click',
            () => {

                state.option.page++;

                render('option');

            }
        );


    document
        .getElementById('menuPrev')
        ?.addEventListener(
            'click',
            () => {

                if (state.menu.page > 1) {

                    state.menu.page--;

                    render('menu');

                }

            }
        );


    document
        .getElementById('menuNext')
        ?.addEventListener(
            'click',
            () => {

                state.menu.page++;

                render('menu');

            }
        );


    render('option');
    render('menu');

})();
</script>

@endpush

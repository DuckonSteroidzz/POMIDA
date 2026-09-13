{{--
    ONE structure for BOTH Add and Edit — the only thing that differs between
    the two modes is the MECHANISM the "+ Add" button uses, decided in JS at
    click-time by whether the entry row carries a data-url (Edit — fetch()
    posts to the real add-ingredient endpoint) or not (Add — a plain row is
    appended client-side, carrying hidden ingredients[i][...] inputs, saved
    with the rest of the item in one request). The markup itself never
    branches by mode; that branching was the bug.

    $blockId          'add', or an existing menu item's id. Names every id below.
    $recipe           Collection of saved MenuItemIngredient (with inventory
                       loaded). Empty for Add mode — nothing is saved yet.
    $draftRows        Add mode only: old('ingredients') rows to redraw as table
                       rows (with their hidden inputs) after a failed submit, so
                       nothing typed is lost. Always [] for Edit.
    $inventoryItems   Selectable rows for the entry-row dropdown (already
                       scoped to branch + is_active by the controller).
    $addUrl           Edit mode: route to POST a new ingredient to.
                       Add mode: null — the entry row's data-url stays empty,
                       which is exactly what tells the JS to append client-side.
    $costLine         ['cost' => float, 'is_fallback' => bool]. Edit mode: the
                       server-computed MenuItemCosting breakdown for the item.
                       Add mode: the same arithmetic run over $draftRows, so a
                       reopened failed submit shows the right figure even
                       before any JS has run.

    STYLING: every class here (form-label-custom, form-control-custom,
    btn-primary-custom) is defined globally in admin/layout.blade.php, and
    .recipe-ing-add-row's responsive rule ships in the stylesheet block of
    menu-items.blade.php — the only page that includes this partial. Everything
    else below is styled inline; the remaining recipe-* names are JS hooks,
    not stylesheet selectors, and MenuItemAddWithIngredientsTest asserts each
    one is both rendered here and actually queried there.

    There are deliberately NO stock badges on the entry row (the earlier
    .ri-badge / -ok / -low / -out set is gone with the rest of that editor) —
    see the picker's change handler in menu-items.blade.php. The per-ingredient
    figures still travel as the data-* payload on each <option>, which is what
    the live cost preview computes from.
--}}
@php
    $recipeEmpty = $recipe->isEmpty() && empty($draftRows);
@endphp

<div class="recipe-empty-notice" id="recipe-empty-{{ $blockId }}" style="background:#fff8e1; color:#6B4E00; font-weight:500; padding:0.4rem 0.6rem; border-radius:6px; font-size:0.74rem; margin-bottom:0.5rem; {{ $recipeEmpty ? '' : 'display:none;' }}">
    <i class="bi bi-exclamation-triangle"></i> No recipe ingredients assigned yet.
</div>

<div style="overflow-x:auto;">
<table style="width:100%; font-size:0.78rem; margin-bottom:0.5rem; {{ $recipeEmpty ? 'display:none;' : '' }}" id="recipe-table-{{ $blockId }}">
    <thead>
        <tr style="text-align:left; color:#374151; font-size:0.72rem; font-weight:600;">
            <th style="padding:0.3rem 0.4rem;">Ingredient</th>
            <th style="padding:0.3rem 0.4rem;">Quantity</th>
            <th style="padding:0.3rem 0.4rem; text-align:right;"></th>
        </tr>
    </thead>
    <tbody id="recipe-tbody-{{ $blockId }}">
        {{-- Saved rows (Edit) or nothing yet (Add). --}}
        @foreach($recipe as $row)
        {{-- data-unit-cost + data-qty let the live "Cost from recipe" preview
             (recalcRecipeCost() in menu-items.blade.php) recompute from the
             rows on screen after an ingredient is added or removed in Edit
             mode, the same way it already does from draft rows in Add mode. --}}
        <tr style="border-top:1px solid #f0f0f0;" data-ingredient-id="{{ $row->id }}" data-inventory-id="{{ $row->inventory_id }}" data-unit-cost="{{ $row->inventory->unit_cost ?? 0 }}" data-qty="{{ $row->quantity_used }}">
            <td style="padding:0.35rem 0.4rem;">{{ $row->inventory->item_name ?? '(removed)' }}</td>
            <td style="padding:0.35rem 0.4rem;">
                {{ rtrim(rtrim(number_format($row->quantity_used, 3), '0'), '.') }} {{ $row->inventory->unit ?? '' }}
            </td>
            <td style="padding:0.35rem 0.4rem; text-align:right;">
                <button type="button" class="recipe-ing-delete-btn" data-url="{{ route('admin.menu-items.ingredients.delete', [$blockId, $row->id]) }}" style="background:#C0392B; color:white; border:none; border-radius:6px; padding:0.2rem 0.5rem; font-size:0.7rem; cursor:pointer;">
                    <i class="bi bi-trash3"></i>
                </button>
            </td>
        </tr>
        @endforeach

        {{-- Draft rows (Add only) — redrawn from old() after a failed submit.
             Same row shape as a saved one, but the delete button removes it
             client-side (data-draft="1") and it carries the two hidden inputs
             that are the actual POST payload for this row. data-unit-cost lets
             the JS total recompute without a lookup. --}}
        @foreach($draftRows as $i => $draftRow)
            @php $draftInv = $inventoryItems->firstWhere('id', (int) ($draftRow['inventory_id'] ?? 0)); @endphp
        <tr style="border-top:1px solid #f0f0f0;" data-draft-row="{{ $i }}" data-unit-cost="{{ $draftInv->unit_cost ?? 0 }}">
            <td style="padding:0.35rem 0.4rem;">{{ $draftInv->item_name ?? '(unknown)' }}</td>
            <td style="padding:0.35rem 0.4rem;">
                {{ rtrim(rtrim(number_format((float) ($draftRow['quantity_used'] ?? 0), 3), '0'), '.') }} {{ $draftInv->unit ?? '' }}
            </td>
            <td style="padding:0.35rem 0.4rem; text-align:right;">
                <button type="button" class="recipe-ing-delete-btn" data-draft="1" style="background:#C0392B; color:white; border:none; border-radius:6px; padding:0.2rem 0.5rem; font-size:0.7rem; cursor:pointer;">
                    <i class="bi bi-trash3"></i>
                </button>
                <input type="hidden" name="ingredients[{{ $i }}][inventory_id]" value="{{ $draftRow['inventory_id'] ?? '' }}">
                <input type="hidden" name="ingredients[{{ $i }}][quantity_used]" value="{{ $draftRow['quantity_used'] ?? '' }}">
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>

<div class="recipe-ing-error" id="recipe-error-{{ $blockId }}" style="display:none; color:#C0392B; background:#fdecea; border-radius:6px; padding:0.4rem 0.6rem; font-size:0.72rem; margin-bottom:0.5rem;"></div>

{{-- The ONE entry row, identical in both modes. Not a <form> — see the
     comment above the Recipe Ingredients section in menu-items.blade.php for
     why: this whole partial lives inside #itemForm, and a nested <form> is
     invalid HTML the browser silently drops. data-url present = Edit
     (fetch()); absent = Add (append client-side). --}}
<div class="recipe-ing-add-row" data-url="{{ $addUrl ?? '' }}" data-block="{{ $blockId }}" style="display:flex; gap:0.4rem; align-items:flex-end; flex-wrap:wrap;">
    <div style="flex:2; min-width:170px;">
        <label class="form-label-custom">Ingredient</label>
        <select class="form-control-custom recipe-ing-select" style="margin-bottom:0;">
            <option value="">-- Select --</option>
            @foreach(($inventoryItems ?? []) as $invItem)
                @include('admin.partials.recipe-ingredient-option', ['invItem' => $invItem, 'selected' => null])
            @endforeach
        </select>
    </div>
    <div style="flex:1; min-width:120px;">
        <label class="form-label-custom">
            Quantity Used <span class="recipe-unit-label" style="color:#4B5563; font-weight:600;"></span>
        </label>
        <input type="number" class="form-control-custom recipe-ing-qty" step="0.001" min="0.001" style="margin-bottom:0;">
    </div>
    <button type="button" class="btn-primary-custom recipe-ing-add-btn" style="padding:0.5rem 0.9rem;">
        <i class="bi bi-plus"></i> Add
    </button>
</div>

<div style="border-top:1px solid #f0f0f0; margin-top:0.6rem; padding-top:0.45rem; font-size:0.78rem; color:#1F2937;">
    <strong>Cost from recipe:</strong>
    <span class="recipe-cost-total" id="recipe-cost-{{ $blockId }}" style="font-weight:700;">₱{{ number_format($costLine['cost'] ?? 0, 2) }}</span>
    @if($costLine['is_fallback'] ?? false)
        <span style="background:#fff3cd;color:#856404;padding:0.1rem 0.45rem;border-radius:10px;font-size:0.65rem;font-weight:600;margin-left:0.3rem;">No recipe</span>
    @endif
    <span class="recipe-profit" id="recipe-profit-{{ $blockId }}" style="color:#4B5563; font-size:0.7rem; font-weight:600;"></span>
</div>

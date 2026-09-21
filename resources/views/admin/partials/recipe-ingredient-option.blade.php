{{--
    One <option> in an ingredient picker. Its own partial so the data-* payload
    the stock line and the live cost preview read from is defined exactly once.

    $invItem     Inventory row
    $selected    currently chosen inventory id, or null
    $branchName  name of the branch this inventory row belongs to, or null.
                 Shown after the unit so two same-named items in different
                 branches (every branch stocks its own "Cheese") can never be
                 mistaken for one another — under "All Branches" the picker
                 used to list every branch's rows unlabeled.

    data-name is the item name on its own. The Add/Edit JS in
    menu-items.blade.php reads it for the row it appends and for the
    "already in the recipe" message, because it used to recover the name by
    stripping a trailing "(unit)" off the option's text — which the branch
    label, sitting after the unit, would otherwise have leaked into.
--}}
<option value="{{ $invItem->id }}"
    data-unit="{{ $invItem->unit }}"
    data-cost="{{ $invItem->unit_cost }}"
    data-stock="{{ $invItem->quantity }}"
    data-low="{{ $invItem->low_stock_alert }}"
    data-name="{{ $invItem->item_name }}"
    {{ (string) ($selected ?? '') === (string) $invItem->id ? 'selected' : '' }}>
    {{ $invItem->item_name }}{{ $invItem->unit ? ' (' . $invItem->unit . ')' : '' }}{{ ($branchName ?? null) ? ' — ' . $branchName : '' }}
</option>

{{--
    One <option> in an ingredient picker. Its own partial so the data-* payload
    the stock line and the live cost preview read from is defined exactly once.

    $invItem   Inventory row
    $selected  currently chosen inventory id, or null
--}}
<option value="{{ $invItem->id }}"
    data-unit="{{ $invItem->unit }}"
    data-cost="{{ $invItem->unit_cost }}"
    data-stock="{{ $invItem->quantity }}"
    data-low="{{ $invItem->low_stock_alert }}"
    {{ (string) ($selected ?? '') === (string) $invItem->id ? 'selected' : '' }}>
    {{ $invItem->item_name }}{{ $invItem->unit ? ' (' . $invItem->unit . ')' : '' }}
</option>

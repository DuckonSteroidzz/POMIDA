{{-- An ad's branch scope: the same two badges the Vouchers list uses for a
     NULL vs a set branch_id. $ad --}}
@if($ad->branch_id)
<span class="ads-badge is-branch"><span aria-hidden="true">📍</span> {{ $ad->branch->name ?? 'Branch #'.$ad->branch_id }}</span>
@else
<span class="ads-badge is-global"><span aria-hidden="true">🌐</span> Global</span>
@endif

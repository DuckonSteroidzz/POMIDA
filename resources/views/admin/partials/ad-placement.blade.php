{{-- An ad's placement, as the Ads list's row and phone card both print it.
     An old cart/orders ad (Ad::hasRemovedPlacement) keeps its grey label and
     the note that no customer page shows it. $ad, $placementLabels --}}
@if($ad->hasRemovedPlacement())
<span class="ads-place is-removed">{{ ucfirst($ad->placement) }}</span>
<span class="ads-place-note">No longer used — never shown</span>
@else
<span class="ads-place"><span aria-hidden="true">{{ $placementLabels[$ad->placement][0] }}</span> {{ $placementLabels[$ad->placement][1] }}</span>
@endif
